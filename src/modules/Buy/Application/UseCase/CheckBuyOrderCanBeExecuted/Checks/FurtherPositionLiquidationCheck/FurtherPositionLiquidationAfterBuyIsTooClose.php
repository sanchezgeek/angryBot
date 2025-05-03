<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\FurtherPositionLiquidationCheck;

use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyCheckFailureEnum;
use App\Domain\Price\Price;
use App\Helper\OutputHelper;
use App\Trading\Application\Check\Contract\AbstractTradingCheckResult;

final readonly class FurtherPositionLiquidationAfterBuyIsTooClose extends AbstractTradingCheckResult
{
    private function __construct(
        public Price $withPrice,
        public Price $liquidationPrice,
        public float $safeDistance,
        string $source,
        string $info,
    ) {
        parent::__construct(false, $source, $info, BuyCheckFailureEnum::FurtherLiquidationIsTooClose);
    }

    public static function create(string|object $source, Price $withPrice, Price $liquidationPrice, float $safeDistance, string $info): self
    {
        $source = is_string($source) ? $source : OutputHelper::shortClassName($source);

        return new self($withPrice, $liquidationPrice, $safeDistance, OutputHelper::shortClassName($source), $info);
    }

    public function actualDistance(): float
    {
        return $this->liquidationPrice->deltaWith($this->withPrice);
    }
}
