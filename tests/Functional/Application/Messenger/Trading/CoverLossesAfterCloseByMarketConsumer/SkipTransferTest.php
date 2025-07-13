<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Messenger\Trading\CoverLossesAfterCloseByMarketConsumer;

use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumer;
use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumerDto;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Stop\Application\Contract\CreateStopHandlerInterface;
use App\Tests\Factory\Position\PositionBuilder;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;

/**
 * @covers \App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumer
 */
class SkipTransferTest extends CoverLossesAfterCloseByMarketConsumerTestAbstract
{
    /**
     * @dataProvider closedPositionDataProvider
     */
    public function testSkipForSupport(Position $closedPosition): void
    {
        $symbol = $closedPosition->symbol;

        $closedPosition->setOppositePosition(
            PositionBuilder::oppositeFor($closedPosition)->size($closedPosition->size + 0.001)->build()
        );

        $this->haveAllOpenedPositionsWithLastMarkPrices([
            $closedPosition->entryPrice => $closedPosition,
        ]);

        $loss = 10.101;

        $this->havePosition($symbol, $closedPosition);
        $this->haveAvailableSpotBalance($symbol, $loss);
        $this->haveContractWalletBalance($symbol, 0, 0);

        # act
        ($this->consumer)(CoverLossesAfterCloseByMarketConsumerDto::forPosition($closedPosition, $loss));
    }

    /**
     * @dataProvider closedPositionDataProvider
     */
    public function testSkipIfSpotBalanceIsInsufficient(Position $closedPosition): void
    {
        $this->haveAllOpenedPositionsWithLastMarkPrices([
            $closedPosition->entryPrice => $closedPosition,
        ]);

        $loss = 10.101;

        $this->havePosition($symbol = $closedPosition->symbol, $closedPosition);
        $this->haveAvailableSpotBalance($symbol, $loss - 0.1);
        $this->haveContractWalletBalance($symbol, 0, 0);

        # act
        ($this->consumer)(CoverLossesAfterCloseByMarketConsumerDto::forPosition($closedPosition, $loss));
    }

    /**
     * @dataProvider closedPositionDataProvider
     */
    public function testSkipIfSpotBalanceIsInsufficien1tForFulfillNegativeFreeContract(Position $closedPosition): void
    {
        $this->haveAllOpenedPositionsWithLastMarkPrices([
            $closedPosition->entryPrice => $closedPosition,
        ]);

        $symbol = $closedPosition->symbol;
        $coin = $symbol->associatedCoin();

        $loss = 10.101;
        $freeContractBalance = -20;
        $availableSpotBalance = 19.9;

        $this->havePosition($symbol, $closedPosition);

        $exchangeAccountServiceMock = $this->createMock(ExchangeAccountServiceInterface::class);
        $exchangeAccountServiceMock->expects(self::once())->method('getContractWalletBalance')->with($coin)->willReturn(new ContractBalance($coin, 100, 0, $freeContractBalance));
        $exchangeAccountServiceMock->expects(self::once())->method('getSpotWalletBalance')->with($coin)->willReturn(new SpotBalance($coin, $availableSpotBalance, $availableSpotBalance));
        $consumer = new CoverLossesAfterCloseByMarketConsumer(
            self::getContainer()->get(ExchangeServiceInterface::class),
            $exchangeAccountServiceMock,
            self::getContainer()->get(PositionServiceInterface::class),
            self::getContainer()->get(ByBitLinearPositionService::class),
            $this->settingsProvider,
            self::createMock(AppNotificationsServiceInterface::class),
            self::createMock(CreateStopHandlerInterface::class),
            self::createMock(TradingParametersProviderInterface::class),
        );

        # assert
        $exchangeAccountServiceMock->expects(self::never())->method('interTransferFromSpotToContract');

        # act
        ($consumer)(CoverLossesAfterCloseByMarketConsumerDto::forPosition($closedPosition, $loss));
    }

    public function closedPositionDataProvider(): iterable
    {
        yield 'support (SHORT)' => [PositionBuilder::short()->build()];
        yield 'support (LONG)' => [PositionBuilder::long()->build()];
    }
}
