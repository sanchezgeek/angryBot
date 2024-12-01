<?php

namespace App\Command\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Exception\SandboxHedgeIsEquivalentException;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactory;
use App\Application\UseCase\Trading\Sandbox\TradingSandbox;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\ValueObject\Side;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Infrastructure\Cache\PositionsCache;
use App\Worker\AppContext;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function abs;
use function explode;
use function implode;
use function sprintf;
use function substr;

#[AsCommand(name: 's:test')]
class SandboxTestCommand extends AbstractCommand
{
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    public const ORDERS_OPTION = 'orders';
    public const DEBUG_OPTION = 'deb';
    public const FORCE_OPTION = 'force';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption(self::ORDERS_OPTION, 'o', InputOption::VALUE_REQUIRED)
            ->addOption(self::DEBUG_OPTION, null, InputOption::VALUE_NEGATABLE, 'Debug?')
            ->addOption(self::FORCE_OPTION, null, InputOption::VALUE_NEGATABLE)
            ->configurePriceRangeArgs()
        ;
    }

    /**
     * @return BuyOrder[]|Stop[]
     */
    private function getOrders(): array
    {
        $ticker = $this->exchangeService->ticker($this->getSymbol());
        $positionSide = $this->getPositionSide();

        $orders = [];
        foreach (explode('|', $this->paramFetcher->getStringOption(self::ORDERS_OPTION)) as $orderDefinition) {
            $type = substr($orderDefinition, 0, 1); $volume = substr($orderDefinition, 1);
            $orders[] = match ($type) {
                '+' => new BuyOrder(1, $ticker->lastPrice, (float)$volume, $positionSide),
                '-' => new Stop(1, $ticker->lastPrice->value(), (float)$volume, 1, $positionSide),
                default => throw new InvalidArgumentException('Invalid type provided: ' . $type . ' ("+/-" expected)')
            };
        }

        return $orders;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->positionsCache->clearPositionsCache($this->getSymbol());

        AppContext::setIsDebug($this->isDebugEnabled());

        try {
            $position = $this->getPosition();
        } catch (Exception $e) {
            $this->io->note($e->getMessage());
        }
        $positionSide = $this->getPositionSide();
        $symbol = $this->getSymbol();

        $orders = $this->getOrders();
        $infoMsg = $this->getInfoMsg($this->getSymbol(), $positionSide, $orders);

        $sandbox = $this->tradingSandboxFactory->byCurrentState($symbol, true);

        [$realContractBalance, ] = $this->printCurrentStats($sandbox, 'BEFORE make buy in sandbox', true);
        $sandbox->processOrders(...$orders);
        $this->printCurrentStats($sandbox, 'AFTER make buy in sandbox');

        if (!$this->paramFetcher->getBoolOption(self::FORCE_OPTION) && !$this->io->confirm($infoMsg)) {
            return Command::FAILURE;
        }

        foreach ($orders as $order) {
            if ($order instanceof BuyOrder) {
                $this->makeBuy($order);
            } else {
                $this->tradeService->closeByMarket($position, $order->getVolume());
            }
        }

        # stats after REAL EXEC
        $this->printCurrentStats($sandbox, 'AFTER real exec', true, $realContractBalance);

        return Command::SUCCESS;
    }

    private function printCurrentStats(TradingSandbox $sandbox, string $description, bool $printRealPositionStats = false, ContractBalance $prevContractBalance = null): array
    {
        $currentState = $sandbox->getCurrentState();

        // @todo print freeForLiq
        $freeBalance = $currentState->getFreeBalance();
        $availableBalance = $currentState->getAvailableBalance();

        try {
            $mainPosition = $currentState->getMainPosition();
        } catch (SandboxHedgeIsEquivalentException $e) {
            throw new RuntimeException(sprintf('%s: No main position found', $description));
        }

        $realPosition = $this->positionService->getPosition($this->getSymbol(), $mainPosition->side);

        OutputHelper::print('');
        OutputHelper::notice($description);
        $realContractBalance = $this->exchangeAccountService->getContractWalletBalance($this->getSymbol()->associatedCoin());
        if ($printRealPositionStats) {
            OutputHelper::print(sprintf('real       contractBalance: %s', $realContractBalance));
            if ($prevContractBalance) {
                OutputHelper::print(sprintf('                                                                     prev - current : %.3f', $prevContractBalance->total() - $realContractBalance->total()));
            }
        }
        OutputHelper::print(sprintf('calculated contractBalance: %s available | %s free', $availableBalance, $freeBalance));
        OutputHelper::print($mainPosition->getCaption());
        $printRealPositionStats && OutputHelper::positionStats('real       ', $realPosition);
        OutputHelper::positionStats('calculated ', $mainPosition);
        OutputHelper::print(sprintf('                                                                     real - calculated : %.3f', $mainPosition->liquidationPrice()->differenceWith($realPosition->liquidationPrice())->deltaForPositionLoss($realPosition->side)));

        return [$realContractBalance];
    }

    public function makeBuy(BuyOrder $buyOrder): ExchangeOrder
    {
        $positionSide = $this->getPositionSide();
        $symbol = $this->getSymbol();
        $ticker = $this->exchangeService->ticker($symbol);

        $order = new ExchangeOrder($symbol, $buyOrder->getVolume(), $ticker->lastPrice);

        if ($this->isDebugEnabled()) {
            $position = $this->getPosition();
            $prevBalance = $this->currentBalance();
            $openFee = $this->orderCostCalculator->openFee($order);
            $closeFee = $this->orderCostCalculator->closeFee($order, $position->leverage, $positionSide);

            # preData
            OutputHelper::print([
                sprintf('%s', $position->getCaption()) => $position->entryPrice,
                'estimated' => [
                    'im' => $this->orderCostCalculator->orderMargin($order, $position->leverage),
                    'fees' => sprintf('toOpen: %s | toClose: %s', $openFee, $closeFee),
                    'totalCost' => $this->orderCostCalculator->totalBuyCost($order, $position->leverage, $positionSide),
                ],
            ]);
        }

        $this->tradeService->marketBuy($symbol, $positionSide, $order->getVolume());

        if ($this->isDebugEnabled()) {
            $newBalance = $this->currentBalance();
            $balanceDelta = $prevBalance->deltaWith($newBalance);
            $realCost = abs($balanceDelta->availableBalance);
            $orderMarginWithoutOpenFee = $realCost - $openFee->value();
            $orderMarginWithoutAnyFees = $realCost - $openFee->value() - $closeFee->value();
            $data = ['prevBalance' => $prevBalance, 'newBalance ' => $newBalance, 'balanceDelta' => $balanceDelta, 'real' => sprintf('real: %.6f, without-open-fee: %.6f, without-any-fees: %.6f', $realCost, $orderMarginWithoutOpenFee, $orderMarginWithoutAnyFees)];
//            if ($position->isSupportPosition()) {$data['supportOrderCost'] = ['realCost-rate-with-estimatedOrderCost' => new Percent($realCost * 100 / $estimatedOrderCost->value(), false), 'orderMarginWithoutOpenFee-rate-with-estimatedOrderCost' => new Percent($orderMarginWithoutOpenFee * 100 / $estimatedOrderCost->value(), false), 'orderMarginWithoutOpenFee-rate-with-estimatedMargin' => new Percent($orderMarginWithoutOpenFee * 100 / $estimatedMargin->value(), false), 'orderMarginWithoutAnyFees-rate-with-estimatedMargin' => new Percent($orderMarginWithoutAnyFees * 100 / $estimatedMargin->value(), false), 'rateWithMargin' => new Percent($realCost * 100 / $estimatedMargin->value(), false)];}
            OutputHelper::print($data);
        }

        return $order;
    }

    private function isDebugEnabled(): bool
    {
        return $this->paramFetcher->getBoolOption(self::DEBUG_OPTION);
    }

    public function currentBalance(): ContractBalance
    {
        return $this->exchangeAccountService->getContractWalletBalance($this->getSymbol()->associatedCoin());
    }

    private function getInfoMsg(Symbol $symbol, Side $positionSide, array $orders): string
    {
        $ordersPart = [];
        foreach ($orders as $order) {
            $ordersPart[] = ($order instanceof BuyOrder ? '->buy' : '->close') . ' ' . $order->getVolume();
        }

        return sprintf("You're about to:        %s       on '%s'. Continue?", implode("    ", $ordersPart), $symbol->name, $positionSide->title());
    }

    /**
     * @param ByBitExchangeAccountService $exchangeAccountService
     */
    public function __construct(
        private readonly OrderServiceInterface $tradeService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly OrderCostCalculator $orderCostCalculator,
        private readonly PositionsCache $positionsCache,
        private readonly TradingSandboxFactory $tradingSandboxFactory,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
