<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result;

use App\Domain\Price\Price;
use App\Helper\OutputHelper;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;

final readonly class FurtherPositionLiquidationAfterBuyIsTooClose extends AbstractTradingCheckResult
{
    private function __construct(
        public Price $withPrice,
        public Price $liquidationPrice,
        public float $safeDistance,
        string $source,
        string $info,
        bool $quiet = false
    ) {
        parent::__construct(false, $source, $info, BuyCheckFailureEnum::FurtherLiquidationIsTooClose, $quiet);
    }

    public static function create(string|TradingCheckInterface $source, Price $withPrice, Price $liquidationPrice, float $safeDistance, string $info): self
    {
        $source = is_string($source) ? $source : $source->alias();

        return new self($withPrice, $liquidationPrice, $safeDistance, OutputHelper::shortClassName($source), $info);
    }

    public function actualDistance(): float
    {
        return $this->liquidationPrice->deltaWith($this->withPrice);
    }

    public function quietClone(): AbstractTradingCheckResult
    {
        return new self($this->withPrice, $this->liquidationPrice, $this->safeDistance, $this->source, $this->info, true);
    }
}
