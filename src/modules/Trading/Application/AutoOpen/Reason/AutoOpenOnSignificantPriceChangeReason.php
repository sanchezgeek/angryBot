<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Reason;

use App\Screener\Application\Contract\Query\FindSignificantPriceChangeResponse;

final class AutoOpenOnSignificantPriceChangeReason implements ReasonForOpenPositionInterface
{
    public function __construct(
        public FindSignificantPriceChangeResponse $significantPriceChangeResponse
    ) {
    }

    public function getStringInfo(): string
    {
        $info = $this->significantPriceChangeResponse;
        $priceChangePercent = $info->info->getPriceChangePercent()->setOutputFloatPrecision(2);

        return sprintf(
            'significantPriceChange: [days=%.2f from %s].price=%s vs curr.price = %s: Δ = %s (%s > %s)',
            $info->info->partOfDayPassed,
            $info->info->fromDate->format('m-d'),
            $info->info->fromPrice,
            $info->info->toPrice,
            $info->info->priceDelta(),
            $priceChangePercent,
            $info->pricePercentChangeConsideredAsSignificant->setOutputFloatPrecision(2), // @todo | priceChange | +/-
        );
    }
}
