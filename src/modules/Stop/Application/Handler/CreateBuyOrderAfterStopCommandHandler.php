<?php

declare(strict_types=1);

namespace App\Stop\Application\Handler;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Domain\Entity\BuyOrder as BO;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Buy\Application\Service\BaseStopLength\Processor\PredefinedStopLengthProcessor;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\PredefinedStopLength;
use App\Domain\BuyOrder\Enum\BuyOrderState;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\Order;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PriceDistanceSelector as D;
use App\Domain\Trading\Enum\RiskLevel as R;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Info\Contract\DependencyInfoProviderInterface;
use App\Info\Contract\Dto\AbstractDependencyInfo;
use App\Info\Contract\Dto\InfoAboutEnumDependency;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\Helper\SettingsHelper;
use App\Stop\Application\Contract\Command\CreateBuyOrderAfterStop;
use App\Stop\Application\Settings\CreateOppositeBuySettings;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Modules\Stop\Applicaiton\Handler\CreateBuyOrdersAfterStopCommandHandlerTest
 *
 * @todo | stop | opposite | store BuyOrder.def to dinamically recalculate prices based on initial refPrice and current RiskLevel
 */
#[AsMessageHandler]
final class CreateBuyOrderAfterStopCommandHandler implements DependencyInfoProviderInterface,  AppDynamicParametersProviderInterface
{
    public function getDependencyInfo(): AbstractDependencyInfo
    {
        // либо можно полное имя метода, а дальше спарсить тег
        return InfoAboutEnumDependency::create(self::class, R::class, 'use RiskLevel to determine martingale setting usage. Info: `./bin/console parameters:show martingale.isMartingaleEnabled`');
    }

    public const int BIG_STOP_VOLUME_MULTIPLIER = 10;

    public const int DEFAULT_ATR_PERIOD = PredefinedStopLengthProcessor::DEFAULT_PERIOD_FOR_ATR;
    public const TimeFrame DEFAULT_ATR_TIMEFRAME = PredefinedStopLengthProcessor::DEFAULT_TIMEFRAME_FOR_ATR;

    public const CreateOppositeBuySettings MARTINGALE_SETTING = CreateOppositeBuySettings::Martingale_Enabled;
    public const CreateOppositeBuySettings FORCE_BUY_ENABLED_SETTING = CreateOppositeBuySettings::Add_Force_Flag_Enabled;

    public function __invoke(CreateBuyOrderAfterStop $command): array
    {
        $stop = $this->stopRepository->find($command->stopId);
        $isAdditionalFixationsStop =
            $stop->isStopAfterOtherSymbolLoss() ||
            $stop->isStopAfterFixHedgeOppositePosition() ||
            $stop->createdAsLockInProfit() ||
            $stop->isCreatedAsFixationStop()
        ;

        if (!$stop->isWithOppositeOrder() && !$isAdditionalFixationsStop) {
            return [];
        }

        $symbol = $stop->getSymbol();
        $side = $stop->getPositionSide();
        $stopVolume = $stop->getVolume();
        $stopPrice = $symbol->makePrice($stop->getPrice()); // $price = $stop->getOriginalPrice() ?? $stop->getPrice();

        $distanceOverride = $stop->getOppositeOrderDistance();

        $isMinVolume = $stopVolume <= $symbol->minOrderQty();
        $isBigStop = FloatHelper::round($stopVolume / $command->prevPositionSize) >= 0.1;

        $refPrice = $stopPrice;
        if ($isAdditionalFixationsStop) {
            $refPrice = $symbol->makePrice($command->prevPositionEntryPrice);
        }

        $riskLevel = $this->tradingParametersProvider->riskLevel($symbol, $side);
        $forceBuyEnabled = $this->isForceBuyEnabled($symbol, $side);

        $martingaleOrders = [];
        $orders = [];
        $active = true;
        if ($isMinVolume || !$isBigStop || $distanceOverride) {
            if ($distanceOverride instanceof Percent) {
                $distanceOverride = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distanceOverride, $stopPrice);
            }

            $distance = $distanceOverride ?? $this->orderDistance($symbol, $refPrice, match ($riskLevel) {
                R::Aggressive => D::Long,
                R::Conservative => D::Standard,
                R::Cautious => D::Short,
            });

            $orders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume, $distance, [BO::FORCE_BUY_CONTEXT => $forceBuyEnabled && !$isBigStop]);
        } else {
            $withForceBuy = [BO::FORCE_BUY_CONTEXT => $forceBuyEnabled];

            if ($isAdditionalFixationsStop) {
                $active = false;
                $kind = match (true) {
                    $stop->isStopAfterOtherSymbolLoss() => 'other.symbol.loss',
                    $stop->isStopAfterFixHedgeOppositePosition() => 'hedge',
                    $stop->createdAsLockInProfit() => 'lock.in.profit',
                    $stop->isCreatedAsFixationStop() => 'fixation',
                    default => 'unknown.fixation'
                };

                $orders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 3.5, 0, BO::addOppositeFixationKind($withForceBuy, $kind));
                $orders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 3.5, match ($riskLevel) {
                    R::Aggressive => D::Short,
                    default => D::VeryShort,
                    R::Cautious => D::VeryVeryShort,
                }, BO::addOppositeFixationKind($withForceBuy, $kind));
                $orders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 5.5, match ($riskLevel) {
                    R::Aggressive => D::Short,
                    default => D::VeryShort,
                    R::Cautious => D::VeryVeryShort,
                }, BO::addOppositeFixationKind([], $kind));
                $orders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 5.5, match ($riskLevel) {
                    R::Aggressive => D::Standard,
                    default => D::Short,
                    R::Cautious => D::VeryShort,
                }, BO::addOppositeFixationKind([], $kind));
            } else {
                $doubleHashes = $command->ordersDoublesHashes ?? [];

                if ($this->isMartingaleEnabled($symbol, $side, $riskLevel)) {
                    $martingaleOrdersStopLength = match ($riskLevel) {
                        R::Aggressive => D::BetweenShortAndStd,
                        R::Conservative => D::Short,
                        R::Cautious => D::VeryShort,
                    };

                    $forcedWithShortStop = BO::addStopCreationStrategyToContext($withForceBuy, new PredefinedStopLength($martingaleOrdersStopLength));

                    if (!$doubleHashes) {
                        $doubleHashes[] = md5(uniqid('BO_double', true));
                        $doubleHashes[] = md5(uniqid('BO_double', true));
                        $doubleHashes[] = md5(uniqid('BO_double', true));
                    }

                    // check if there was significant priceChange in the last ... 3 minutes (avoiding mistakes)
                    // or add tries counter (+ check prom position opened datetime)
//                    if ($isBigStop) {
//                        // some part immediately after stop price (if big part of position closed)
//                        $orderLengthImmediatelyAfterStop = $this->getOppositeOrderDistance($stop->getSymbol(), $refPrice, PriceDistanceSelector::VeryVeryShort) / 2;
//                        $forcedWithVeryVeryShortStop = BuyOrder::addStopCreationStrategyToContext($withForceBuy, new PredefinedStopLength(PriceDistanceSelector::VeryShort));
//
//                        $martingaleOrders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 2, $orderLengthImmediatelyAfterStop, array_merge($forcedWithVeryVeryShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[0]]), sign: -1);
//                    }

                    $doubleLongOrderDistance = $this->orderDistance($symbol, $refPrice, D::DoubleLong);
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 2, $doubleLongOrderDistance * 2.2, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[2]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 2, $doubleLongOrderDistance * 1.9, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[1]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 2, $doubleLongOrderDistance * 1.7, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[0]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 2, $doubleLongOrderDistance * 1.5, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[2]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 2, $doubleLongOrderDistance, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[1]), sign: -1);

                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 2, D::Long, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[0]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 2, D::BetweenLongAndStd, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[2]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 3, D::Standard, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[1]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 3, D::BetweenShortAndStd, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[0]), sign: -1);

                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 3, D::BetweenShortAndStd, BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[2]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 2,
                        match ($riskLevel) {R::Aggressive => D::VeryShort, R::Conservative => D::Short, R::Cautious => D::BetweenShortAndStd},
                        BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[1]), sign: -1
                    );
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 3,
                        match ($riskLevel) {R::Aggressive => D::VeryVeryShort, R::Conservative => D::VeryShort, R::Cautious => D::Short},
                        BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[0]), sign: -1
                    );
                    $martingaleOrders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 3,
                        match ($riskLevel) {R::Aggressive => D::AlmostImmideately, R::Conservative => D::VeryVeryShort, R::Cautious => D::VeryShort},
                        BO::addDoubleFlag($forcedWithShortStop, $doubleHashes[0]), sign: -1
                    );
                }

                $stopLengthContext = [];
                if ($riskLevel === R::Cautious) {
                    $stopLengthContext = BO::addStopCreationStrategyToContext($stopLengthContext, new PredefinedStopLength(D::Short));
                } elseif ($riskLevel === R::Aggressive) {
                    $stopLengthContext = BO::addStopCreationStrategyToContext($stopLengthContext, new PredefinedStopLength(D::BetweenLongAndStd));
                }

                $orders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 5,
                    match ($riskLevel) {
                        R::Aggressive => D::VeryVeryShort,
                        R::Conservative => D::VeryShort,
                        R::Cautious => D::Short,
                    },
                    BO::addDoubleFlag(array_merge($stopLengthContext, $withForceBuy), $doubleHashes[0] ?? null)
                );

                $orders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 3,
                    match ($riskLevel) {
                        R::Aggressive => D::VeryShort,
                        R::Conservative => D::Short,
                        R::Cautious => D::BetweenShortAndStd,
                    },
                    BO::addDoubleFlag($stopLengthContext, $doubleHashes[1] ?? null)
                );

                $orders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 5,
                    match ($riskLevel) {
                        R::Aggressive => D::Short,
                        R::Conservative => D::BetweenShortAndStd,
                        R::Cautious => D::Standard,
                    },
                    BO::addDoubleFlag(array_merge($stopLengthContext, $withForceBuy), $doubleHashes[2] ?? null)
                );

                $orders[] = $this->orderBasedOnLength($stop, $refPrice, $stopVolume / 5,
                    match ($riskLevel) {
                        R::Aggressive => D::BetweenShortAndStd,
                        R::Conservative => D::Standard,
                        R::Cautious => D::BetweenLongAndStd,
                    },
                    $stopLengthContext
                );
            }
        }

        $orders = array_filter($orders);
        $martingaleOrders = array_filter($martingaleOrders);

        $orders = new OrdersLimitedWithMaxVolume(
            new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$orders)),
            $stopVolume,
            $symbol,
            $side
        );

        $buyOrders = [];

        // active
        foreach ($orders->getOrders() as $order) {
            $dto = new CreateBuyOrderEntryDto($symbol, $side, $order->volume(), $order->price()->value(), $order->context(), $active ? BuyOrderState::Active : null);
            $buyOrders[] = $this->createBuyOrderHandler->handle($dto)->buyOrder;
        }

        foreach ($martingaleOrders as $order) {
            $dto = new CreateBuyOrderEntryDto($symbol, $side, $order->volume(), $order->price()->value(), $order->context());
            $buyOrders[] = $this->createBuyOrderHandler->handle($dto)->buyOrder;
        }

        return $buyOrders;
    }

    private function orderBasedOnLength(Stop $stop, SymbolPrice $refPrice, float $volume, D|float $length, array $additionalContext = [], int $sign = 1): ?Order
    {
        $commonContext = [
            BO::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BO::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
            BO::OPPOSITE_SL_ID_CONTEXT => $stop->getId(),
        ];

        $side = $stop->getPositionSide();

        $distance = $length instanceof D ? $this->orderDistance($stop->getSymbol(), $refPrice, $length) : $length;
        $distance*= $sign;

        try {
            $price = $side->isShort() ? $refPrice->sub($distance) : $refPrice->add($distance);
        } catch (PriceCannotBeLessThanZero $e) {
            return null;
        }

        $volume = $stop->getSymbol()->roundVolume($volume);
        $context = array_merge($commonContext, $additionalContext);

        return new Order($price, $volume, $context);
    }

    /**
     * @param D $lengthSelector // @todo | oppositeBuyOrder | use param from stop?
     */
    private function orderDistance(SymbolInterface $symbol, SymbolPrice $refPrice, D $lengthSelector): float
    {
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(
            PnlHelper::transformPriceChangeToPnlPercent(
                $this->tradingParametersProvider->transformLengthToPricePercent($symbol, $lengthSelector, self::DEFAULT_ATR_TIMEFRAME, self::DEFAULT_ATR_PERIOD)
            ),
            $refPrice
        );
    }

    /**
     * @todo `stop.oppositeBuy`
     */
    #[AppDynamicParameter(group: 'stopOpposite')]
    public function isMartingaleEnabled(SymbolInterface $symbol, Side $side, R $riskLevel): bool
    {
        $override = SettingsHelper::exactForSymbolAndSideOrSymbol(self::MARTINGALE_SETTING, $symbol, $side);
        if ($override !== null) {
            return $override;
        }

        $overrideForSide = SettingsHelper::exactOptional(self::MARTINGALE_SETTING, null, $side);

        if ($overrideForSide === false) {
            return false;
        } elseif ($overrideForSide === true) {
            return true;
        }

        if ($riskLevel === R::Cautious) {
            return false;
        }

        return SettingsHelper::withAlternatives(self::MARTINGALE_SETTING, $symbol, $side) === true;
    }

    private function isForceBuyEnabled(SymbolInterface $symbol, Side $side): bool
    {
        return SettingsHelper::withAlternatives(self::FORCE_BUY_ENABLED_SETTING, $symbol, $side) === true;
    }

    public function __construct(
        #[AppDynamicParameterAutowiredArgument]
        private readonly StopRepository $stopRepository,
        private readonly TradingParametersProviderInterface $tradingParametersProvider,
        #[AppDynamicParameterAutowiredArgument]
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
    ) {
    }
}
