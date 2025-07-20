<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Liquidation\Job;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Liquidation\Application\Job\RemoveStaleStops\RemoveStaleStopsMessage;
use App\Liquidation\Application\Job\RemoveStaleStops\RemoveStaleStopsMessageHandler;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\Logger\AppErrorsSymfonyLoggerTrait;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group liquidation
 *
 * @covers RemoveStaleStopsMessageHandler
 */
class RemoveOrdersWithFakeExchangeOrderIdTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;
    use ByBitV5ApiRequestsMocker;
    use AppErrorsSymfonyLoggerTrait;
    use SettingsAwareTest;

    private LiquidationDynamicParametersInterface|MockObject $liquidationDynamicParameters;
    private RemoveStaleStopsMessageHandler $handler;

    protected function setUp(): void
    {
        $this->handler = self::getContainer()->get(RemoveStaleStopsMessageHandler::class);
    }

    public function testRemoveStaleStops(): void
    {
        $stops = [
            new Stop(10, 30290, 0.1, null, SymbolEnum::BTCUSDT, Side::Buy),
            self::markAsStale(new Stop(11, 30290, 0.1, null, SymbolEnum::BTCUSDT, Side::Buy)),
            new Stop(12, 30290, 0.1, null, SymbolEnum::BTCUSDT, Side::Sell),
            self::markAsStale(new Stop(13, 30290, 0.1, null, SymbolEnum::BTCUSDT, Side::Sell)),

            new Stop(20, 30290, 0.1, null, SymbolEnum::ETHUSDT, Side::Buy),
            self::markAsStale(new Stop(21, 30290, 0.1, null, SymbolEnum::ETHUSDT, Side::Buy)),
            new Stop(22, 30290, 0.1, null, SymbolEnum::ETHUSDT, Side::Sell),
            self::markAsStale(new Stop(23, 30290, 0.1, null, SymbolEnum::ETHUSDT, Side::Sell)),
        ];

        foreach ($stops as $stop) {
            $this->applyDbFixtures(new StopFixture($stop));
        }

        $message = new RemoveStaleStopsMessage();

        ($this->handler)($message);


        self::seeStopsInDb(
            new Stop(10, 30290, 0.1, null, SymbolEnum::BTCUSDT, Side::Buy),
            new Stop(12, 30290, 0.1, null, SymbolEnum::BTCUSDT, Side::Sell),

            new Stop(20, 30290, 0.1, null, SymbolEnum::ETHUSDT, Side::Buy),
            new Stop(22, 30290, 0.1, null, SymbolEnum::ETHUSDT, Side::Sell),
        );
    }

    private static function markAsStale(Stop $stop): Stop
    {
        return $stop->setFakeExchangeOrderId();
    }
}
