<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result;

use App\Domain\Price\SymbolPrice;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;

final readonly class BuyOrderPlacedTooFarFromPositionEntry extends AbstractTradingCheckResult
{
    private function __construct(
        public SymbolPrice $positionEntryPrice,
        public SymbolPrice $orderPrice,
        public Percent $maxAllowedPercentChange,
        public Percent $orderPricePercentChangeFromPositonEntry,
        string $source,
        string $info,
        bool $quiet = false
    ) {
        parent::__construct(false, $source, $info, BuyCheckFailureEnum::BuyOrderPlacedTooFarFromPositionEntry, $quiet);
    }

    public static function create(
        string|TradingCheckInterface $source,
        SymbolPrice $positionEntryPrice,
        SymbolPrice $orderPrice,
        Percent $maxAllowedPercentChange,
        Percent $orderPricePercentChangeFromPositonEntry,
        string $info
    ): self {
        $source = is_string($source) ? $source : $source->alias();

        return new self($positionEntryPrice, $orderPrice, $maxAllowedPercentChange, $orderPricePercentChangeFromPositonEntry, OutputHelper::shortClassName($source), $info);
    }

    public function quietClone(): AbstractTradingCheckResult
    {
        return new self($this->positionEntryPrice, $this->orderPrice, $this->maxAllowedPercentChange, $this->orderPricePercentChangeFromPositonEntry, $this->source, $this->info, true);
    }
}
