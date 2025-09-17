<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Result;

use App\Domain\Value\Percent\Percent;
use JsonSerializable;
use Stringable;

final class ConfidenceRateVotesCollection implements JsonSerializable, Stringable
{
    /** @var ConfidenceRateDecision[]  */
    private array $votes;

    public function __construct(
        ConfidenceRateDecision ...$votes
    ) {
        $this->votes = $votes;
    }

    public function add(ConfidenceRateDecision $vote): self
    {
        $this->votes[] = $vote;

        return $this;
    }

    /**
     * @return Percent 0% and higher =)
     */
    public function getResultRate(): Percent
    {
        $resultRate = 1;

        foreach ($this->votes as $vote) {
            $resultRate = $vote->rate->of($resultRate);
        }

        return Percent::fromPart($resultRate, false);
    }

    public function getResultInfo(): array
    {
        $info = [];

        foreach ($this->votes as $vote) {
            if ($vote->rate->part() === 1.0) {
                continue;
            }

            $info[] = sprintf('x%.2f from %s (%s)', $vote->rate->part(), $vote->source, $vote->info);
        }

        return $info;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'votes' => $this->votes,
            'resultInfo' => $this->getResultInfo(),
        ];
    }

    public function __toString(): string
    {
        return json_encode($this);
    }
}
