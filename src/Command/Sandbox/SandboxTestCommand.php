<?php

namespace App\Command\Sandbox;

use App\Application\UseCase\OrderExecution\Sandbox\ExecutionSandbox;
use App\Application\UseCase\OrderExecution\Sandbox\ExecutionSandboxFactory;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Infrastructure\Cache\PositionsCache;
use App\Worker\AppContext;
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

#[AsCommand(name: 'sandbox:test')]
class SandboxTestCommand extends AbstractCommand
{
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    public const ORDERS_OPTION = 'orders';
    public const DEBUG_OPTION = 'deb';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption(self::ORDERS_OPTION, 'o', InputOption::VALUE_REQUIRED)
            ->addOption(self::DEBUG_OPTION, null, InputOption::VALUE_NEGATABLE, 'Debug?')
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
                '+' => new BuyOrder(1, $ticker->markPrice, (float)$volume, $positionSide),
                '-' => new Stop(1, $ticker->markPrice->value(), (float)$volume, 1, $positionSide),
                default => throw new InvalidArgumentException('Invalid type provided: ' . $type . ' ("+/-" expected)')
            };
        }

        return $orders;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->positionsCache->clearPositionsCache($this->getSymbol());

        AppContext::setIsDebug($this->isDebugEnabled());

        $position = $this->getPosition();
        $symbol = $this->getSymbol();

        $orders = $this->getOrders();
        $infoMsg = $this->getInfoMsg($position, $orders);

        # create sandbox
        $executionSandbox = $this->executionSandboxFactory->make($symbol, true);

        # stats before SANDBOX EXEC
        $this->printCurrentStats($executionSandbox, 'BEFORE make buy in sandbox');

        $executionSandbox->processOrders(...$orders);

        # stats after SANDBOX EXEC
        $this->printCurrentStats($executionSandbox, 'AFTER make buy in sandbox');

        if (!$this->io->confirm($infoMsg)) {
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
        $this->printCurrentStats($executionSandbox, 'AFTER real exec', true);

        return Command::SUCCESS;
    }

    private function printCurrentStats(ExecutionSandbox $sandbox, string $description, bool $printRealPositionStats = false): void
    {
        $freeBalance = $sandbox->getCurrentState()->getFreeBalance();
        $availableBalance = $sandbox->getCurrentState()->getAvailableBalance();

        $mainPosition = $sandbox->getCurrentState()->getMainPosition();
        if (!$mainPosition) {
            throw new RuntimeException(sprintf('%s: No positions found', $description));
        }

        $realPosition = $this->positionService->getPosition($this->getSymbol(), $mainPosition->side);

        OutputHelper::print('');
        OutputHelper::notice($description);
        if ($printRealPositionStats) {
            $coin = $this->getSymbol()->associatedCoin();
            $realContractBalance = $this->exchangeAccountService->getContractWalletBalance($coin);
            OutputHelper::print(sprintf('real       contractBalance: %s', $realContractBalance));
        }
        OutputHelper::print(sprintf('calculated contractBalance: %s available | %s free', $availableBalance, $freeBalance));
        OutputHelper::print($mainPosition->getCaption());
        $printRealPositionStats && OutputHelper::positionStats('real       ', $realPosition);
        OutputHelper::positionStats('calculated ', $mainPosition);
        OutputHelper::print(sprintf('                                                                     real - calculated : %.3f', $mainPosition->liquidationPrice()->differenceWith($realPosition->liquidationPrice())->deltaForPositionLoss($realPosition->side)));
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

    public function currentBalance(): WalletBalance
    {
        return $this->exchangeAccountService->getContractWalletBalance($this->getSymbol()->associatedCoin());
    }

    private function getInfoMsg(Position $position, array $orders): string
    {
        $ordersPart = [];
        foreach ($orders as $order) {
            $ordersPart[] = ($order instanceof BuyOrder ? '->buy' : '->close') . ' ' . $order->getVolume();
        }

        return sprintf("You're about to:        %s       on '%s'. Continue?", implode("    ", $ordersPart), $position->getCaption());
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
        private readonly ExecutionSandboxFactory $executionSandboxFactory,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
