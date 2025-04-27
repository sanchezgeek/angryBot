<?php

namespace App\Command\Trade;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Order;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_merge;
use function count;
use function implode;
use function in_array;
use function is_float;
use function random_int;
use function round;
use function sprintf;
use function str_contains;
use function str_replace;

#[AsCommand(name: 'order:place')]
class PlaceOrderCommand extends AbstractCommand
{
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    private const TYPE_OPTION = 'type';

    private const MARKET_BUY = 'buy';
    private const MARKET_CLOSE = 'close';
    private const LIMIT_TP = 'limitTP';
    private const DELAYED_TP = 'delayedTP';

    private const TYPES = [self::MARKET_BUY, self::MARKET_CLOSE, self::LIMIT_TP, self::DELAYED_TP];

    private const TP_PRICE_OPTION = 'tpPrice';
    private const LIMIT_TP_PRICE_STEP_OPTION = 'step';

    public const VOLUME_ARGUMENT = 'volume';

    public const WITHOUT_CONFIRMATION_OPTION = 'y';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument(self::VOLUME_ARGUMENT, InputArgument::REQUIRED, sprintf('Volume value || %% of position size (in `%s` mode)', self::MARKET_CLOSE))
            ->addOption(self::TYPE_OPTION, '-k', InputOption::VALUE_REQUIRED, 'Type (' . implode(', ', self::TYPES) . ')')
            ->addOption(self::TP_PRICE_OPTION, '-p', InputOption::VALUE_REQUIRED, 'Limit TP price | PositionPNL%. Use [`from` + `to` + `step`], if you need place orders in price range (actual for `limitTP` and `delayedTP` modes)')
            ->addOption(self::LIMIT_TP_PRICE_STEP_OPTION, '-s', InputOption::VALUE_REQUIRED, 'Price step (in case of `from` + `to`)')
            ->addOption(self::WITHOUT_CONFIRMATION_OPTION, null, InputOption::VALUE_NEGATABLE, 'Without confirm')
            ->configurePriceRangeArgs()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $this->getType();
        $side = $this->getPositionSide();

        if ($type === self::MARKET_BUY) {
            $except = [Symbol::BTCUSDT];

            $symbols = $this->getSymbols($except);

            $additional = null;
            try {
                $volume = $this->paramFetcher->getFloatArgument(self::VOLUME_ARGUMENT);
            } catch (Exception $e) {
                $volumeDefinition = $this->paramFetcher->getStringArgument(self::VOLUME_ARGUMENT);
                if (str_contains($volumeDefinition, 'ofPosition')) {
                    $additional = 'ofPosition';
                    $volumePartDefinition = str_replace('ofPosition', '', $volumeDefinition);
                    $volume = Percent::string($volumePartDefinition);
                } else {
                    throw $e;
                }
            }

            if (count($symbols) > 1 && !$volume instanceof Percent) {
                throw new InvalidArgumentException('Invalid usage: when more than one symbol specified, it must be Percent of current position volume as `volume` argument');
            }

            if (count($symbols) > 1) {
                $msg = sprintf('You\'re about to buy %s%s on %d symbols". Continue?', $volume, $additional ?? '', count($symbols));
            } else {
                $msg = sprintf('You\'re about to buy %s on "%s %s". Continue?', $volume, $symbols[0]->value, $side->title());
            }

            if (!$this->isWithoutConfirm() && !$this->io->confirm($msg)) {
                return Command::FAILURE;
            }

            $this->doMarketBuy($symbols, $side, $volume, $additional);
        }

        if ($type === self::MARKET_CLOSE) {
            // @todo | maybe need move to some trait (code duplicates)
            $positionSizePart = null;
            try {
                $positionSizePart = $this->paramFetcher->getPercentArgument(self::VOLUME_ARGUMENT);
                $volume = $this->getPosition()->getVolumePart($positionSizePart);
            } catch (InvalidArgumentException|RuntimeException) {
                $volume = $this->paramFetcher->getFloatArgument(self::VOLUME_ARGUMENT);
            }
            $position = $this->getPosition();

            $msg = sprintf(
                'You\'re about to close %s of "%s" position. Continue?',
                $positionSizePart !== null ? sprintf('%.1f%%', $positionSizePart) : $volume,
                $position->getCaption(),
            );

            if (!$this->io->confirm($msg)) {
                return Command::FAILURE;
            }

            $this->tradeService->closeByMarket($position, $volume);
        }

        if (in_array($type, [self::LIMIT_TP, self::DELAYED_TP])) {
            $volume = $this->paramFetcher->getFloatArgument(self::VOLUME_ARGUMENT);

            $position = $this->getPosition();

            try {
                $priceRange = $this->getPriceRange();
            } catch (\InvalidArgumentException $e) {
                $price = $this->getPriceFromPnlPercentOptionWithFloatFallback(self::TP_PRICE_OPTION);
            }

            $orders = [];
            if (isset($priceRange)) {
                $step = $this->paramFetcher->getIntOption(self::LIMIT_TP_PRICE_STEP_OPTION);

                foreach ($priceRange->byStepIterator($step, $side) as $price) {
                    $rand = round(random_int(-7, 8) * 0.4, 2);
                    $orders[] = new Order($price->sub($rand), $volume);
                }

                $msg = sprintf('You\'re about to add %d %.3f %ss on "%s" position. Continue?', count($orders), $volume, $type, $position->getCaption());
                if (!$this->io->confirm($msg)) {
                    return Command::FAILURE;
                }
            } elseif (isset($price)) {
                $msg = sprintf('You\'re about to add %s on "%s" position. Continue?', $type, $position->getCaption());
                if (!$this->io->confirm($msg)) {
                    return Command::FAILURE;
                }
                $orders[] = new Order($price, $volume);
            } else {
                throw new InvalidArgumentException('`priceRange` or `price` must be specified');
            }

            $context = ['uniqid' => $uniqueId = $this->uniqueIdGenerator->generateUniqueId('delayed-tp-grid')];
            foreach ($orders as $order) {
                if ($type === self::DELAYED_TP) {
                    $context = array_merge($context, Stop::getTakeProfitContext());
                    $this->stopService->create($this->getSymbol(), $position->side, $order->price()->value(), $order->volume(), Stop::getTakeProfitTriggerDelta(), $context);
                } else {
                    $this->tradeService->addLimitTP($position, $order->volume(), $order->price()->value());
                }
            }

            if ($type === self::DELAYED_TP) {
                $this->io->success(sprintf('DelayedTPs uniqueID: %s', $uniqueId));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param Symbol[] $symbols
     */
    private function doMarketBuy(array $symbols, Side $positionSide, float|Percent $volume, string $mode = null): void
    {
        if ($mode === 'ofPosition' && !$volume instanceof Percent) {
            throw new InvalidArgumentException(
                sprintf('Invalid usage: when selected `%s` mode, $volume argument must be of type Percent', $mode),
            );
        }

        $orders = [];
        foreach ($symbols as $symbol) {
            $ticker = $this->exchangeService->ticker($symbol);
            if (is_float($volume)) {
                $orders[] = ExchangeOrder::roundedToMin($symbol, $volume, $ticker->indexPrice);
            } else {
                if ($mode === 'ofPosition') {
                    if (!($position = $this->positionService->getPosition($symbol, $positionSide))) {
                        continue;
                    }

                    $qtyCalculated = $volume->of($position->size);
                    $qtyRounded = $symbol->roundVolume($qtyCalculated);

                    $orders[] = ExchangeOrder::roundedToMin($symbol, $qtyRounded, $ticker->indexPrice);
                } else {
                    throw new InvalidArgumentException(sprintf('Unrecognized option `%s`', $mode ?? ''));
                }
            }
        }

        if ($orders) {
            foreach ($orders as $order) {
                if ($order->getProvidedVolume() !== $order->getVolume()) {
                    if (!$this->isWithoutConfirm() && !$this->io->confirm(
                        sprintf(
                            'Calculated volume for "%s" not equals initially provided one. Calculated: %s, initial: %s. Are you sure you want to buy %s on %s %s?',
                            $order->getSymbol()->value,
                            $order->getVolume(),
                            $order->getProvidedVolume(),
                            $order->getVolume(),
                            $order->getSymbol()->value,
                            $positionSide->title(),
                        ),
                    )) {
                        throw new Exception('OK.');
                    }
                }
            }

            OutputHelper::print(sprintf('You attempt to buy:'), '');
            foreach ($orders as $order) {
                OutputHelper::print(sprintf('%s %s: %s', $order->getSymbol()->value, $positionSide->title(), $order->getVolume()));
            }
            OutputHelper::print('');

            if (!$this->isWithoutConfirm() && !$this->io->confirm('Sure?')) {
                throw new Exception('OK.');
            }

            foreach ($orders as $order) {
                $try = true;

                while ($try) {
                    try {
                        $this->tradeService->marketBuy($order->getSymbol(), $positionSide, $order->getVolume());
                        $try = false;
                    } catch (CannotAffordOrderCostException $e) {
                        $currentContract = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());
                        $cost = $this->orderCostCalculator->totalBuyCost($order, new Leverage(100), $this->getPositionSide());

                        $diff = $cost->sub($currentContract->available);
                        $this->exchangeAccountService->interTransferFromSpotToContract($order->getSymbol()->associatedCoin(), $diff->value());
                    } catch (Throwable $e) {
                        OutputHelper::print(sprintf('Got "%s" error while trying to buy %s on %s %s', $e->getMessage(), $order->getVolume(), $order->getSymbol()->value, $positionSide->title()));
                    }
                }

            }
        }
    }

    private function getType(): string
    {
        $mode = $this->paramFetcher->getStringOption(self::TYPE_OPTION);
        if (!in_array($mode, self::TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $mode provided (%s)', $mode),
            );
        }

        return $mode;
    }

    private function getPriceFromPnlPercentOptionWithFloatFallback(string $name, bool $required = true): ?Price
    {
        try {
            $pnlValue = $this->paramFetcher->requiredPercentOption($name);
            return PnlHelper::targetPriceByPnlPercentFromPositionEntry($this->getPosition(), $pnlValue);
        } catch (InvalidArgumentException) {
            try {
                return $this->getSymbol()->makePrice($this->paramFetcher->requiredFloatOption($name));
            } catch (InvalidArgumentException $e) {
                if ($required) {
                    throw $e;
                }

                return null;
            }
        }
    }

    private function isWithoutConfirm(): bool
    {
        return $this->paramFetcher->getBoolOption(self::WITHOUT_CONFIRMATION_OPTION);
    }

    public function __construct(
        private readonly OrderServiceInterface $tradeService,
        private readonly StopServiceInterface $stopService,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly OrderCostCalculator $orderCostCalculator,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
