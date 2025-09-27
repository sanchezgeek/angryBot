<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Trading;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParameters;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyOnLongDistanceAndCheckAveragePrice;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Value\Percent\Percent;
use App\Tests\Stub\TA\TradingParametersProviderStub;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

trait TradingParametersMocker
{
    private static ?TradingParametersProviderStub $tradingParametersProviderStub = null;

    /**
     * @before
     */
    public static function createTradingParametersStub(): TradingParametersProviderStub
    {
        if (!self::$tradingParametersProviderStub) {
            self::$tradingParametersProviderStub = new TradingParametersProviderStub();
        }

        try {
            self::setTradingParametersStubInContainer(self::$tradingParametersProviderStub);
        } catch (InvalidArgumentException $e) {
        }

        return self::$tradingParametersProviderStub;
    }

    public static function getMockedTradingParametersStub(): TradingParametersProviderStub
    {
        if (!self::$tradingParametersProviderStub) {
            throw new RuntimeException('Initialize $tradingParametersProviderStub first with ::createTradingParametersStub');
        }

        return self::$tradingParametersProviderStub;
    }

    public static function setTradingParametersStubInContainer(TradingParametersProviderInterface $provider): void
    {
        self::getContainer()->set(TradingParametersProviderInterface::class, $provider);
    }

    public static function mockTradingParametersForLiquidationTests(SymbolInterface $symbol, ?string $percentOverride = null): void
    {
        $percent = $percentOverride ?? '0.8%';

        self::$tradingParametersProviderStub->addTransformedLengthResult(
            percentResult: Percent::string($percent),
            symbol: $symbol,
            distanceSelector: LiquidationDynamicParameters::WARNING_RANGE,
            period: 7
        );

        $pct = match ($symbol->name()) {
            'BTCUSDT' => 0.15,
            'ETHUSDT', 'BNBUSDT' => 0.2,
            default => 0.5,
        };

        self::$tradingParametersProviderStub->addTransformedLengthResult(
            percentResult: new Percent($pct),
            symbol: $symbol,
            distanceSelector: LiquidationDynamicParameters::ACTUAL_STOPS_RANGE,
            period: TradingParametersProviderInterface::ATR_PERIOD_FOR_ORDERS,
        );
    }

    public static function mockTradingParametersForBuyOnLongDistanceTests(SymbolInterface $symbol, ?string $allowedPercentChange = null): void
    {
        $percent = $allowedPercentChange ?? '10%';

        self::$tradingParametersProviderStub->addTransformedLengthResult(
            Percent::string($percent),
            $symbol,
            BuyOnLongDistanceAndCheckAveragePrice::DEFAULT_MAX_ALLOWED_PRICE_DISTANCE,
            TradingParametersProviderInterface::LONG_ATR_TIMEFRAME,
            TradingParametersProviderInterface::ATR_PERIOD_FOR_ORDERS,
        );
    }

    protected static function mockTradingParametersStubWithAllPredefinedLengths(SymbolInterface $symbol): TradingParametersProviderStub
    {
        self::$tradingParametersProviderStub
            ->addTransformedLengthResult(Percent::string('0.1%'), $symbol, PriceDistanceSelector::AlmostImmideately)
            ->addTransformedLengthResult(Percent::string('0.3%'), $symbol, PriceDistanceSelector::VeryVeryShort)
            ->addTransformedLengthResult(Percent::string('0.5%'), $symbol, PriceDistanceSelector::VeryShort)
            ->addTransformedLengthResult(Percent::string('0.6%'), $symbol, PriceDistanceSelector::Short)
            ->addTransformedLengthResult(Percent::string('0.7%'), $symbol, PriceDistanceSelector::BetweenShortAndStd)
            ->addTransformedLengthResult(Percent::string('1%'), $symbol, PriceDistanceSelector::Standard)
            ->addTransformedLengthResult(Percent::string('1.5%'), $symbol, PriceDistanceSelector::BetweenLongAndStd)
            ->addTransformedLengthResult(Percent::string('2%'), $symbol, PriceDistanceSelector::Long)
            ->addTransformedLengthResult(Percent::string('10%'), $symbol, PriceDistanceSelector::VeryLong)
            ->addTransformedLengthResult(Percent::string('20%'), $symbol, PriceDistanceSelector::VeryVeryLong)
            ->addTransformedLengthResult(Percent::string('30%'), $symbol, PriceDistanceSelector::DoubleLong)
        ;

        return self::$tradingParametersProviderStub;
    }
}
