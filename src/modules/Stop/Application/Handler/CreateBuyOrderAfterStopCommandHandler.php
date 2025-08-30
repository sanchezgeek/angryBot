<?php

declare(strict_types=1);

namespace App\Stop\Application\Handler;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Buy\Application\Service\BaseStopLength\Processor\PredefinedStopLengthProcessor;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\PredefinedStopLength;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\Order;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PriceDistanceSelector as Distance;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Trading\Enum\TradingStyle;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Settings\Application\Helper\SettingsHelper;
use App\Stop\Application\Contract\Command\CreateBuyOrderAfterStop;
use App\Stop\Application\Settings\CreateOppositeBuySettings;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Modules\Stop\Applicaiton\Handler\CreateBuyOrdersAfterStopCommandHandlerTest
 */
#[AsMessageHandler]
final class CreateBuyOrderAfterStopCommandHandler
{
    public const int BIG_STOP_VOLUME_MULTIPLIER = 10;

    public const int DEFAULT_ATR_PERIOD = PredefinedStopLengthProcessor::DEFAULT_PERIOD_FOR_ATR;
    public const TimeFrame DEFAULT_ATR_TIMEFRAME = PredefinedStopLengthProcessor::DEFAULT_TIMEFRAME_FOR_ATR;

    public const CreateOppositeBuySettings MARTINGALE_SETTING = CreateOppositeBuySettings::Martingale_Enabled;
    public const CreateOppositeBuySettings FORCE_BUY_ENABLED_SETTING = CreateOppositeBuySettings::Add_Force_Flag_Enabled;

    public function __invoke(CreateBuyOrderAfterStop $command): array
    {
        $stop = $this->stopRepository->find($command->stopId);
        $isAdditionalFixationsStop = $stop->isStopAfterOtherSymbolLoss() || $stop->isStopAfterFixHedgeOppositePosition();

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

        $tradingStyle = $this->tradingParametersProvider->tradingStyle($symbol, $side);
        $forceBuyEnabled = $this->isForceBuyEnabled($symbol, $side);

        $martingaleOrders = [];
        $orders = [];
        if ($isMinVolume || !$isBigStop || $distanceOverride) {
            if ($distanceOverride instanceof Percent) {
                $distanceOverride = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distanceOverride, $stopPrice);
            }

            $distance = $distanceOverride ?? $this->getOppositeOrderDistance($symbol, $refPrice, match ($tradingStyle) {
                TradingStyle::Aggressive => Distance::Long,
                TradingStyle::Conservative => Distance::Standard,
                TradingStyle::Cautious => Distance::Short,
            });

            $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume, $distance, [BuyOrder::FORCE_BUY_CONTEXT => $forceBuyEnabled && !$isBigStop]);
        } else {
            $withForceBuy = [BuyOrder::FORCE_BUY_CONTEXT => $forceBuyEnabled];

            if ($isAdditionalFixationsStop) {
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, match ($tradingStyle) {
                    TradingStyle::Aggressive => Distance::ModerateLong,
                    TradingStyle::Conservative => Distance::Standard,
                    TradingStyle::Cautious => Distance::ModerateShort,
                });
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5, match ($tradingStyle) {
                    TradingStyle::Aggressive => Distance::Standard,
                    TradingStyle::Conservative => Distance::Short,
                    TradingStyle::Cautious => Distance::VeryShort,
                });
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, match ($tradingStyle) {
                    TradingStyle::Aggressive => Distance::Short,
                    TradingStyle::Conservative => Distance::VeryShort,
                    TradingStyle::Cautious => Distance::VeryVeryShort,
                }, $withForceBuy);
                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, 0, $withForceBuy);
            } else {
                $doubleHashes = $command->ordersDoublesHashes ?? [];

                if ($this->isMartingaleEnabled($symbol, $side, $tradingStyle)) {
                    $martingaleOrdersStopLength = match ($tradingStyle) {
                        TradingStyle::Aggressive => Distance::Standard,
                        TradingStyle::Conservative => Distance::ModerateShort,
                        TradingStyle::Cautious => Distance::Short,
                    };

                    $forcedWithShortStop = BuyOrder::addStopCreationStrategyToContext($withForceBuy, new PredefinedStopLength($martingaleOrdersStopLength));

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

                    $martingaleOrders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, Distance::Standard, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, Distance::ModerateLong, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3, Distance::Long, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]]), sign: -1);
                    $martingaleOrders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 2, Distance::VeryLong, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]]), sign: -1);

                    $martingaleOrders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 2,
                        match ($tradingStyle) {
                            TradingStyle::Aggressive => Distance::Short,
                            TradingStyle::Conservative => Distance::ModerateShort,
                            TradingStyle::Cautious => Distance::Standard,
                        },
                        array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[1]]),
                        sign: -1
                    );

                    $martingaleOrders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3,
                        match ($tradingStyle) {
                            TradingStyle::Aggressive => Distance::VeryShort,
                            TradingStyle::Conservative => Distance::Short,
                            TradingStyle::Cautious => Distance::ModerateShort,
                        },
                        array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[0]]),
                        sign: -1
                    );

                    $martingaleOrders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3,
                        match ($tradingStyle) {
                            TradingStyle::Aggressive => Distance::VeryVeryShort,
                            TradingStyle::Conservative => Distance::VeryShort,
                            TradingStyle::Cautious => Distance::Short,
                        },
                        array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[0]]),
                        sign: -1
                    );
                }

                $stopLengthContext = [];
                if ($tradingStyle === TradingStyle::Cautious) {
                    $stopLengthContext = BuyOrder::addStopCreationStrategyToContext($stopLengthContext, new PredefinedStopLength(Distance::Short));
                } elseif ($tradingStyle === TradingStyle::Aggressive) {
                    $stopLengthContext = BuyOrder::addStopCreationStrategyToContext($stopLengthContext, new PredefinedStopLength(Distance::ModerateLong));
                }

                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5,
                    match ($tradingStyle) {
                        TradingStyle::Aggressive => Distance::VeryVeryShort,
                        TradingStyle::Conservative => Distance::VeryShort,
                        TradingStyle::Cautious => Distance::Short,
                    },
                    array_merge($stopLengthContext, $withForceBuy, $doubleHashes ? [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[0]] : [])
                );

                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 3,
                    match ($tradingStyle) {
                        TradingStyle::Aggressive => Distance::VeryShort,
                        TradingStyle::Conservative => Distance::Short,
                        TradingStyle::Cautious => Distance::ModerateShort,
                    },
                    array_merge($stopLengthContext, $doubleHashes ? [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[1]] : [])
                );

                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5,
                    match ($tradingStyle) {
                        TradingStyle::Aggressive => Distance::Short,
                        TradingStyle::Conservative => Distance::ModerateShort,
                        TradingStyle::Cautious => Distance::Standard,
                    },
                    array_merge($stopLengthContext, $withForceBuy, $doubleHashes ? [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]] : [])
                );

                $orders[] = $this->orderBasedOnLengthEnum($stop, $refPrice, $stopVolume / 5,
                    match ($tradingStyle) {
                        TradingStyle::Aggressive => Distance::ModerateShort,
                        TradingStyle::Conservative => Distance::Standard,
                        TradingStyle::Cautious => Distance::ModerateLong,
                    },
                    $stopLengthContext
                );
            }
        }

        $orders = new OrdersLimitedWithMaxVolume(
            new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$orders)),
            $stopVolume
        );

        $buyOrders = [];
        foreach (array_merge(
            $orders->getOrders(),
            $martingaleOrders
                 ) as $order) {
            $dto = new CreateBuyOrderEntryDto($symbol, $side, $order->volume(), $order->price()->value(), $order->context());
            $buyOrders[] = $this->createBuyOrderHandler->handle($dto)->buyOrder;
        }

        return $buyOrders;
    }

    private function orderBasedOnLengthEnum(Stop $stop, SymbolPrice $refPrice, float $volume, Distance|float $length, array $additionalContext = [], int $sign = 1): Order
    {
        $commonContext = [
            BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
            BuyOrder::OPPOSITE_SL_ID_CONTEXT => $stop->getId(),
        ];

        $side = $stop->getPositionSide();

        $distance = $length instanceof Distance ? $this->getOppositeOrderDistance($stop->getSymbol(), $refPrice, $length) : $length;
        $distance*= $sign;

        $price = $side->isShort() ? $refPrice->sub($distance) : $refPrice->add($distance);
        $volume = $stop->getSymbol()->roundVolume($volume);
        $context = array_merge($commonContext, $additionalContext);

        return new Order($price, $volume, $context);
    }

    /**
     * @param Distance $lengthSelector // @todo | oppositeBuyOrder | use param from stop?
     */
    private function getOppositeOrderDistance(SymbolInterface $symbol, SymbolPrice $refPrice, Distance $lengthSelector): float
    {
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(
            PnlHelper::transformPriceChangeToPnlPercent(
                $this->tradingParametersProvider->oppositeBuyLength($symbol, $lengthSelector, self::DEFAULT_ATR_TIMEFRAME, self::DEFAULT_ATR_PERIOD)
            ),
            $refPrice
        );
    }

    private function isMartingaleEnabled(SymbolInterface $symbol, Side $side, TradingStyle $tradingStyle): bool
    {
        $override = SettingsHelper::getForSymbolOrSymbolAndSide(self::MARTINGALE_SETTING, $symbol, $side);
        if ($override !== null) {
            return $override;
        }

        if ($tradingStyle === TradingStyle::Cautious) {
            return false;
        }

        if (SettingsHelper::exact(self::MARTINGALE_SETTING, null, $side) === false) {
            return false;
        }

        return SettingsHelper::withAlternativesAllowed(self::MARTINGALE_SETTING, $symbol, $side) === true;
    }

    private function isForceBuyEnabled(SymbolInterface $symbol, Side $side): bool
    {
        return SettingsHelper::withAlternativesAllowed(self::FORCE_BUY_ENABLED_SETTING, $symbol, $side) === true;
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly TradingParametersProviderInterface $tradingParametersProvider,
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
    ) {
    }
}
