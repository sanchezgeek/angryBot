<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Messenger\Trading\CoverLossesAfterCloseByMarketConsumer;

use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumer;
use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumerDto;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;
use App\Tests\Factory\Position\PositionBuilder;

class SuccessTransferTest extends CoverLossesAfterCloseByMarketConsumerTestAbstract
{
    /**
     * @dataProvider closedPositionDataProvider
     */
    public function testSuccessTransfer(Position $closedPosition): void
    {
        $loss = 10.101;

        $this->havePosition($symbol = $closedPosition->symbol, $closedPosition);
        $this->haveAvailableSpotBalance($symbol, $loss); # sufficient for cover loss
        $this->haveContractWalletBalance($symbol, 0, 0);

        # assert
        $this->expectsInterTransferFromSpotToContract(new CoinAmount($symbol->associatedCoin(), $loss));

        # act
        ($this->consumer)(CoverLossesAfterCloseByMarketConsumerDto::forPosition($closedPosition, $loss));
    }

    public function closedPositionDataProvider(): array
    {
        return [
            [PositionBuilder::short()->build()],
            [PositionBuilder::long()->build()],
        ];
    }

    /**
     * @dataProvider closedPositionWithShortLiquidationDataProvider
     */
    public function testWhenNegativeFreeBalanceIsBiggerThanAvailableSpotButPositionLiquidationInWarningRange(Position $closedPosition): void
    {
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
            $exchangeAccountServiceMock,
            self::getContainer()->get(PositionServiceInterface::class),
            $this->settingsProvider
        );

        # assert
        $exchangeAccountServiceMock->expects(self::once())->method('interTransferFromSpotToContract')->with($coin, $loss);

        # act
        ($consumer)(CoverLossesAfterCloseByMarketConsumerDto::forPosition($closedPosition, $loss));
    }

    public function closedPositionWithShortLiquidationDataProvider(): array
    {
        $warningDistance = CoverLossesAfterCloseByMarketConsumer::LIQUIDATION_DISTANCE_APPLICABLE_TO_NOT_MAKE_TRANSFER - 1;

        return [
            [PositionBuilder::short()->liqDistance($warningDistance)->build()],
            [PositionBuilder::long()->liqDistance($warningDistance)->build()],
        ];
    }
}
