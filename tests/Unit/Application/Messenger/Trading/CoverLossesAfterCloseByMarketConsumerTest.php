<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Messenger\Trading;

use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumer;
use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumerDto;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Domain\Position;
use App\Tests\Factory\Position\PositionBuilder;
use PHPUnit\Framework\TestCase;

class CoverLossesAfterCloseByMarketConsumerTest extends TestCase
{
    private ExchangeAccountServiceInterface $exchangeAccountServiceMock;

    private CoverLossesAfterCloseByMarketConsumer $consumer;

    protected function setUp(): void
    {
        $this->exchangeAccountServiceMock = $this->createMock(ExchangeAccountServiceInterface::class);

        $this->consumer = new CoverLossesAfterCloseByMarketConsumer($this->exchangeAccountServiceMock);
    }

    /**
     * @dataProvider handleTestDataProvider
     */
    public function testHandle(Position $closedPosition, float $loss): void
    {
        $this->exchangeAccountServiceMock->expects(self::once())->method('interTransferFromSpotToContract')->with($closedPosition->symbol->associatedCoin(), $loss);

        ($this->consumer)(CoverLossesAfterCloseByMarketConsumerDto::forPosition($closedPosition, $loss));
    }

    public function handleTestDataProvider(): array
    {
        return [
            [PositionBuilder::short()->build(), 10.101],
            [PositionBuilder::long()->build(), 10.101],
        ];
    }

    /**
     * @dataProvider skipTestDataProvider
     */
    public function testSkip(Position $closedPosition, float $loss): void
    {
        $this->exchangeAccountServiceMock->expects(self::never())->method('interTransferFromContractToSpot');

        ($this->consumer)(CoverLossesAfterCloseByMarketConsumerDto::forPosition($closedPosition, $loss));
    }

    public function skipTestDataProvider(): array
    {
        return [
            [PositionBuilder::short()->withSize(0.5)->withOppositePosition(PositionBuilder::long()->withSize(1)->build())->build(), 10.101],
            [PositionBuilder::long()->withSize(0.5)->withOppositePosition(PositionBuilder::short()->withSize(1)->build())->build(), 10.101],
        ];
    }
}