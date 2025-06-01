<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\Push;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Service\SettingAccessor;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCommonCasesTest;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Utils\TradingSetup\TradingSetup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class PushMultiplePositionsStopsTestAbstract extends KernelTestCase
{
    use ByBitV5ApiTester;
    use StopsTester;
    use BuyOrdersTester;
    use ByBitV5ApiRequestsMocker;
    use MessageConsumerTrait;
    use SettingsAwareTest;

    public static function baseSetup(): TradingSetup
    {
        $setup = new TradingSetup();

        /**
         * DRY from @see PushStopsCommonCasesTest::pushStopsTestCases
         */
        $symbol = Symbol::BTCUSDT;
        $btcLong = PositionBuilder::long()->symbol($symbol)->size(0.5)->entry(28000)->build();
        $btcShort = PositionBuilder::short()->symbol($symbol)->entry(29000)->size(1)->liq(29182.25)->build($btcLong);

        /** @var Stop[] $btcShortStopsBefore */
        $btcShortStopsBefore = [
            100 => StopBuilder::short(100, 29055, 0.4)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create()), # must not be pushed (not active)
            105 => StopBuilder::short(105, 29030, 0.011)->withTD(10)->build()->setIsWithoutOppositeOrder(), # before ticker => push | without oppositeBuy
            110 => StopBuilder::short(110, 29060, 0.1)->withTD(10)->build(), # by tD | with oppositeBuy
            115 => StopBuilder::short(115, 29061, 0.1)->withTD(10)->build(),
            120 => StopBuilder::short(120, 29155, 0.2)->withTD(100)->build(),
            130 => StopBuilder::short(130, 29055, 0.3)->withTD(5)->build(), # by tD | with oppositeBuy
            140 => StopBuilder::short(140, 29029, 0.33)->withTD(5)->build()->setIsTakeProfitOrder(),

            150 => StopBuilder::long(150, 29100, 0.001)->withTD(5)->build()->disableSupportChecks(),
        ];

        $setup->addPosition($btcLong);
        $setup->addPosition($btcShort);

        foreach ($btcShortStopsBefore as $stop) {
            $setup->addStop($stop);
        }

        $adaTicker = TickerFactory::create(Symbol::ADAUSDT, 0.6, 0.6, 0.6);
        $adaLong = PositionBuilder::long()->symbol(Symbol::ADAUSDT)->size(100)->entry(0.5)->build();
        $adaShort = PositionBuilder::short()->symbol(Symbol::ADAUSDT)->size(100)->entry(1.01)->build($adaLong);

        $setup->addPosition($adaLong);
        $setup->addPosition($adaShort);

        $symbol = Symbol::LINKUSDT;
        $linkTicker = TickerFactory::create($symbol, 23.685, 23.687, 23.688);
        $linkLong = PositionBuilder::long()->symbol($symbol)->size(10)->entry(15)->build();
        $linkShort = PositionBuilder::short()->symbol($symbol)->size(11)->entry(20)->liq($linkTicker->markPrice->value() + $symbol->minimalPriceMove() * 300)->build($linkLong);
        $defaultTd = 0.01;

        $linkStopsBefore = [
            200 => StopBuilder::short(200, 23.685, 10, $symbol)->withTD($defaultTd)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create()), # must not be pushed (not active)
            205 => StopBuilder::short(205, 23.684, 11, $symbol)->withTD($defaultTd)->build()->setIsWithoutOppositeOrder(), # before ticker => push | without oppositeBuy
            210 => StopBuilder::short(210, 23.689, 12, $symbol)->withTD($defaultTd)->build()->setOppositeOrdersDistance(0.06), # by tD | with oppositeBuy
            215 => StopBuilder::short(215, 23.696, 12, $symbol)->withTD($defaultTd)->build(),
            // @todo takeProfit order

            230 => StopBuilder::long(230, 23.696, 12, $symbol)->withTD($defaultTd)->build()->disableSupportChecks(),
        ];

        $setup->addPosition($linkLong);
        $setup->addPosition($linkShort);

        foreach ($linkStopsBefore as $stop) {
            $setup->addStop($stop);
        }

        return $setup;
    }

    protected static function warmupSettings(array $settings, array $symbols): void
    {
        $settingsProvider = self::getContainerSettingsProvider();
        foreach ($settings as $setting) {
            foreach ($symbols as $symbol) {
                foreach ([Side::Sell, Side::Buy] as $side) {
                    $settingsProvider->required(SettingAccessor::withAlternativesAllowed($setting, $symbol, $side));
                    $settingsProvider->required(SettingAccessor::exact($setting, $symbol, $side));
                    $settingsProvider->optional(SettingAccessor::withAlternativesAllowed($setting, $symbol, $side));
                    $settingsProvider->optional(SettingAccessor::exact($setting, $symbol, $side));
                }
            }
        }
    }
}
