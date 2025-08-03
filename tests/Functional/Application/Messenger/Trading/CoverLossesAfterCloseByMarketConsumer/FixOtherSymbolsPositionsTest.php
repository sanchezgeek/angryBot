<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Messenger\Trading\CoverLossesAfterCloseByMarketConsumer;

use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumerDto;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;

/**
 * @covers \App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumer
 */
class FixOtherSymbolsPositionsTest extends CoverLossesAfterCloseByMarketConsumerTestAbstract
{

    public function testCover(): void
    {
        self::markTestSkipped('Complete me!');
        $closedPosition = new Position(Side::Sell, SymbolEnum::AAVEUSDT, 300, 1, 2100, 3000, 100, 100, 600);

        $allOpenedPositions = [
            2000 => new Position(Side::Sell, SymbolEnum::ETHUSDT, 3000, 1, 2100, 3000, 100, 100, 600),
            100500 => new Position(Side::Sell, SymbolEnum::BTCUSDT, 200000, 1, 100000, 200000, 1000, 100, 500),
            350 => $closedPosition
        ];
        $this->haveAllOpenedPositionsWithLastMarkPrices($allOpenedPositions);

        $loss = 10.101;

        # act
        ($this->consumer)(CoverLossesAfterCloseByMarketConsumerDto::forPosition($closedPosition, $loss));
    }

}
