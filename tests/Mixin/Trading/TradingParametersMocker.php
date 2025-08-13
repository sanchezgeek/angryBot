<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Trading;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParameters;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyOnLongDistanceAndCheckAveragePrice;
use App\Domain\Value\Percent\Percent;
use App\Tests\Stub\TA\TradingParametersProviderStub;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

trait TradingParametersMocker
{
    private static ?TradingParametersProviderStub $tradingParametersProviderStub = null;

    public function createTradingParametersStub(): TradingParametersProviderStub
    {
        self::$tradingParametersProviderStub = new TradingParametersProviderStub();

        self::setTradingParametersStubInContainer(self::$tradingParametersProviderStub);

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

        self::$tradingParametersProviderStub->addRegularPredefinedStopLengthResult(
            percentResult: Percent::string($percent),
            symbol: $symbol,
            sourceStopLength: LiquidationDynamicParameters::STOP_LENGTH_SELECTOR_FOR_CALCULATE_WARNING_RANGE,
            period: 7
        );
    }

    public static function mockTradingParametersForBuyOnLongDistanceTests(SymbolInterface $symbol, ?string $allowedPercentChange = null): void
    {
        $percent = $allowedPercentChange ?? '10%';

        self::$tradingParametersProviderStub->addRegularOppositeBuyOrderLengthResult(
            $symbol,
            BuyOnLongDistanceAndCheckAveragePrice::DEFAULT_MAX_ALLOWED_PRICE_CHANGE,
            TradingParametersProviderInterface::LONG_ATR_TIMEFRAME,
            TradingParametersProviderInterface::ATR_PERIOD_FOR_ORDERS,
            Percent::string($percent)
        );
    }
}
