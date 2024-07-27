<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Messenger\Trading\CoverLossesAfterCloseByMarketConsumer;

use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumer;
use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumerDto;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SuccessTransferTest extends KernelTestCase
{
    use ByBitV5ApiRequestsMocker;

    private CoverLossesAfterCloseByMarketConsumer $consumer;

    protected function setUp(): void
    {
        $this->consumer = self::getContainer()->get(CoverLossesAfterCloseByMarketConsumer::class);
    }

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
        $exchangeAccountServiceMock->expects(self::once())->method('getContractWalletBalance')->with($coin)->willReturn(new WalletBalance(AccountType::CONTRACT, $coin, 100, 0, $freeContractBalance));
        $exchangeAccountServiceMock->expects(self::once())->method('getSpotWalletBalance')->with($coin)->willReturn(new WalletBalance(AccountType::SPOT, $coin, $availableSpotBalance, $availableSpotBalance));
        $consumer = new CoverLossesAfterCloseByMarketConsumer($exchangeAccountServiceMock, self::getContainer()->get(PositionServiceInterface::class));

        # assert
        $exchangeAccountServiceMock->expects(self::once())->method('interTransferFromSpotToContract')->with($coin, $loss);

        # act
        ($consumer)(CoverLossesAfterCloseByMarketConsumerDto::forPosition($closedPosition, $loss));
    }

    public function closedPositionWithShortLiquidationDataProvider(): array
    {
        $warningDistance = CoverLossesAfterCloseByMarketConsumer::LIQUIDATION_DISTANCE_APPLICABLE_TO_NOT_MAKE_TRANSFER - 1;

        return [
            [PositionBuilder::short()->withLiquidationDistance($warningDistance)->build()],
            [PositionBuilder::long()->withLiquidationDistance($warningDistance)->build()],
        ];
    }
}