<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Stop\Helper\PnlHelper;
use App\Infrastructure\ByBit\Service\ByBitCommissionProvider;
use LogicException;

use function max;
use function sprintf;

/**
 * @todo tests?
 */
class ContractBalanceTestHelper
{
    public static function recalculateContractBalance(
        ContractBalance $prev,
        Position $position,
        SandboxBuyOrder|SandboxStopOrder $sandboxOrder,
        ?float $freeOverride = null
    ): ContractBalance {
        $orderCostCalculator = new OrderCostCalculator(new ByBitCommissionProvider());

        if ($sandboxOrder instanceof SandboxBuyOrder) {
            $exchangeOrder = ExchangeOrder::raw($position->symbol, $sandboxOrder->volume, $sandboxOrder->price);
            $orderCost = $orderCostCalculator->totalBuyCost($exchangeOrder, $position->leverage, $position->side);
            $orderMargin = $orderCostCalculator->orderMargin($exchangeOrder, $position->leverage);

            return new ContractBalance(
                $prev->assetCoin,
                $prev->total->sub($orderCost),
                $prev->available->sub($orderCost)->sub($orderMargin),
                $freeOverride ?? $prev->free->sub($orderCost)->sub($orderMargin),
            );
        } else {
            $expectedPnl = PnlHelper::getPnlInUsdt($position, $sandboxOrder->price, $sandboxOrder->volume);
            $exchangeOrder = ExchangeOrder::raw($position->symbol, $sandboxOrder->volume, $position->entryPrice); // @todo | sandbox | what to use? position entry or stop price
            $orderMargin = $orderCostCalculator->orderMargin($exchangeOrder, $position->leverage);
            $closeFee = $orderCostCalculator->closeFee($exchangeOrder, $position->leverage, $position->side);

            return new ContractBalance(
                $prev->assetCoin,
                $prev->total->sub($closeFee)->add($orderMargin)->add($expectedPnl),
                $prev->available->sub($closeFee)->add($orderMargin)->add($expectedPnl),
                $freeOverride ?? $prev->free->sub($closeFee)->add($orderMargin)->add($expectedPnl),
            );
        }
    }

    /**
     * @deprecated ?
     */
    public static function contractBalanceBasedOnFree(float $free, array $positions, Ticker $ticker): ContractBalance
    {
        $coin = $ticker->symbol->associatedCoin();
        $im = self::totalInitialMargin($coin, ...$positions);

        return new ContractBalance(
            $coin,
            $im->add($free)->value(),
            self::availableBalance($free, $positions, $ticker),
            $free,
        );
    }

    public static function totalInitialMargin(Coin $coin, Position ...$positions): CoinAmount
    {
        foreach ($positions as $position) {
            assert($position->symbol->associatedCoin() === $coin, new LogicException(sprintf('Coin of all provided positions must be %s', $coin->value)));
        }

        $result = new CoinAmount($coin, 0);
        foreach ($positions as $item) {
            $result = $result->add($item->initialMargin);
        }

        return $result;
    }

    private static function availableBalance(float $free, array $positions, Ticker $ticker): float
    {
        $hedge = count($positions) > 1 ? Hedge::create(...$positions) : null;

        if (!$positions || $hedge?->isEquivalentHedge()) {
            return $free;
        } elseif ($hedge) {
            $positionForCalcLoss = $hedge->mainPosition;
        } else {
            $positionForCalcLoss = $positions[0];
        }

        $lastPrice = $ticker->lastPrice;
        if ($positionForCalcLoss?->isPositionInLoss($lastPrice)) {
            $priceDelta = $lastPrice->differenceWith($positionForCalcLoss->entryPrice());
            $loss = $positionForCalcLoss->getNotCoveredSize() * $priceDelta->absDelta();

            $available = $free - $loss;
        } else {
            $available = $free;
        }

        return max($available, 0);
    }
}
