<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Trading\Applicaiton\LockInProfit\Factory;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\Helper\InitialMarginHelper;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mock\Response\ByBitV5Api\Account\GetWalletBalanceResponseBuilder;
use App\Trading\Application\LockInProfit\Factory\LockInProfitStrategiesDtoFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LockInProfitStrategiesDtoFactoryTest extends KernelTestCase
{
    private const float DEPOSIT = 100;

    use ByBitV5ApiRequestsMocker;

    /**
     * @dataProvider cases
     */
    public function testRatio(Position $position, Percent $expectedPercent): void
    {
        $im = InitialMarginHelper::realInitialMargin($position);
        $coin = $position->symbol->associatedCoin();
        $apiResponse = GetWalletBalanceResponseBuilder::ok()->withUnifiedBalance($coin, 100, $im)->build();
        $this->matchGet(new GetWalletBalanceRequest(AccountType::UNIFIED, $coin), $apiResponse);

        /** @var LockInProfitStrategiesDtoFactory $factory */
        $factory = self::getContainer()->get(LockInProfitStrategiesDtoFactory::class);
        $percent = $factory->minPercentToClose($position);

        self::assertEquals($expectedPercent, $percent);
    }

    public static function cases(): array
    {
        return [
            [self::positionBasedOnPercentOfDeposit(20), Percent::string('1.5%')],
            [self::positionBasedOnPercentOfDeposit(4), Percent::string('1.5%')],
            [self::positionBasedOnPercentOfDeposit(2), Percent::string('1%')],
            [self::positionBasedOnPercentOfDeposit(1), Percent::string('0.5%')],
            [self::positionBasedOnPercentOfDeposit(0.6), Percent::string('0.5%')],
            [self::positionBasedOnPercentOfDeposit(0.2), Percent::string('0.5%')],
        ];
    }

    private static function positionBasedOnPercentOfDeposit(float $percentOfDeposit): Position
    {
        $entry = 200;
        $leverage = 100;

        $size = ($percentOfDeposit / 100 * self::DEPOSIT) / ($entry / $leverage);

        return PositionBuilder::short()->symbol(SymbolEnum::AAVEUSDT)->size($size)->entry($entry)->build();
    }
}
