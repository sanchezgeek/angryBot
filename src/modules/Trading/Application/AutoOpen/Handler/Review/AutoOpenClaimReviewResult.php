<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Handler\Review;

use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateVotesCollection;
use App\Trading\Application\AutoOpen\Dto\PositionAutoOpenParameters;

final class AutoOpenClaimReviewResult
{
    /**
     * @param array<array-key, mixed> $info
     */
    public function __construct(
        public ?PositionAutoOpenParameters $suggestedParameters,
        public ?ConfidenceRateVotesCollection $confidenceVotes,
        public array $info = [],
    ) {
        assert(($this->suggestedParameters && $this->confidenceVotes) || $this->info);
    }

    public static function negative(array $info): self
    {
        return new self(null, null, $info);
    }
}
