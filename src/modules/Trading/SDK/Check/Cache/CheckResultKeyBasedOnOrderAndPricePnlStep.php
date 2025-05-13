<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Cache;

use App\Application\Cache\CacheKeyGeneratorInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;

/**
 * @see \App\Tests\Unit\Modules\Trading\SDK\Check\Cache\CheckResultKeyBasedOnOrderAndPricePnlStepTest
 */
final readonly class CheckResultKeyBasedOnOrderAndPricePnlStep implements CacheKeyGeneratorInterface
{
    private float $pnlStep;

    public function __construct(
        private float $orderPrice,
        private float $orderQty,
        private Symbol $symbol,
        private Side $positionSide,
        float|Percent $pnlStep
    ) {
        $this->pnlStep = $pnlStep instanceof Percent ? $pnlStep->value() : $pnlStep;
    }

    function numZeroesAfterPoint(float $x): int
    {
        return -1 - (int)floor(log10($x));
    }

    public function generate(): string
    {
        $priceValue = $this->orderPrice;

        if ($priceValue >= 1) {
            $intLen = strlen((string)(int)$priceValue);
            $modifier = pow(10, $intLen);
            $val = $priceValue / $modifier;
            $rounded = round($val, 1, PHP_ROUND_HALF_UP);
            $range = $rounded * $modifier / 100 / 2;
        } else {
            $zerosCount = self::numZeroesAfterPoint($priceValue);
            $modifier = pow(10, $zerosCount);
            $val = $priceValue * $modifier;
            $rounded = round($val, 1, PHP_ROUND_HALF_UP);
            $range = $rounded / $modifier / 100 / 2;

        }

        $nextLevelValue = $priceValue + $range;

        $intPart = (int) ($nextLevelValue / $range);
        $currentPriceLevel = $this->symbol->makePrice($intPart * $range);

        $stepBasedOnRoundedPrice = PnlHelper::convertPnlPercentOnPriceToAbsDelta($this->pnlStep, $currentPriceLevel);
//        echo PHP_EOL . sprintf('%s -> %s -> %s => %s', $priceValue, $range, $currentPriceLevel, $stepBasedOnRoundedPrice);

        return sprintf(
            'CRKBOPPS_%s_%s_pct_%s_step_%.' . $this->symbol->pricePrecision() . 'f_priceLevel_%d_orderQty_%s',
            $this->symbol->value,
            $this->positionSide->value,
            $this->pnlStep,
            $stepBasedOnRoundedPrice,
            floor($priceValue / $stepBasedOnRoundedPrice),
            $this->orderQty
        );
    }
}
