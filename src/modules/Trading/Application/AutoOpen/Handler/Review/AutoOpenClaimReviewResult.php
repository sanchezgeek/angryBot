<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Handler\Review;

use App\Domain\Position\ValueObject\Side;
use App\Helper\OutputHelper;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateVotesCollection;
use App\Trading\Application\AutoOpen\Dto\PositionAutoOpenParameters;
use App\Trading\Domain\Symbol\SymbolInterface;
use JsonSerializable;

final class AutoOpenClaimReviewResult implements JsonSerializable
{
    /**
     * @param array<array-key, mixed> $info
     */
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public bool $success,
        public ?PositionAutoOpenParameters $suggestedParameters,
        public ?ConfidenceRateVotesCollection $confidenceVotes,
        public array $info = [],
        public bool $silent = false,
    ) {
        assert(!$this->success || ($this->suggestedParameters && $this->confidenceVotes));
        assert($this->success || $this->info);
    }

    public static function negative(SymbolInterface $symbol, Side $positionSide, array $info, bool $silent = false): self
    {
        return new self($symbol, $positionSide, false, null, null, $info, $silent);
    }

    public function info(): array
    {
        return array_merge($this->info, [
            'url' => OutputHelper::urlToSymbolDashboard($this->symbol)
        ]);
    }

    public function jsonSerialize(): array
    {
        return array_merge(get_object_vars($this), [
            'url' => OutputHelper::urlToSymbolDashboard($this->symbol)
        ]);
    }
}
