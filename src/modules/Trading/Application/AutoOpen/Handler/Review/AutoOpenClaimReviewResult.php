<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Handler\Review;

use App\Domain\Position\ValueObject\Side;
use App\Helper\OutputHelper;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateVotesCollection;
use App\Trading\Application\AutoOpen\Dto\PositionAutoOpenParameters;
use App\Trading\Domain\Symbol\SymbolInterface;
use JsonSerializable;
use Stringable;

final class AutoOpenClaimReviewResult implements JsonSerializable, Stringable
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
        return array_merge($this->info, $this->commonInfo());
    }

    public function jsonSerialize(): array
    {
        return array_merge(get_object_vars($this), $this->commonInfo());
    }

    public function __toString(): string
    {
        return json_encode($this, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Some rearrange =)
     */
    private function commonInfo(): array
    {
        return [
            'url' => OutputHelper::urlToSymbolDashboard($this->symbol),
            'symbolSide' => sprintf('%s %s', $this->symbol->name(), $this->positionSide->value),
            'success' => $this->success,
        ];
    }
}
