<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Domain\Position;
use App\Bot\Domain\Strategy\StopCreate;

final readonly class Hedge
{
    private function __construct(
        public Position $mainPosition,
        public Position $supportPosition,
    ) {
    }

    public static function create(Position $a, Position $b): self
    {
        if ($a->side === $b->side) {
            throw new \LogicException('Positions on the same side.');
        }

        return $a->size > $b->size ? new Hedge($a, $b) : new Hedge($b, $a);
    }

    public function isSupportPosition(Position $position): bool
    {
        return $this->supportPosition->side === $position->side;
    }

    public function isMainPosition(Position $position): bool
    {
        return $this->mainPosition->side === $position->side;
    }

    public function needIncreaseSupport(): bool
    {
        $rate = $this->getSupportRate();

        return $rate <= 0.38;
    }

    /**
     * PushBuyOrdersHandler will create small stops with `under_position`
     */
    public function needKeepSupportSize(): bool
    {
        $rate = $this->getSupportRate();

        return $rate < 0.45;
    }

    public function getHedgeStrategy(): HedgeStrategy
    {
        $mainPositionStrategy = StopCreate::AFTER_FIRST_STOP_UNDER_POSITION;
        $supportStrategy = StopCreate::DEFAULT;
        $description = null;

        if ($this->needIncreaseSupport()) {
            $supportStrategy = StopCreate::AFTER_FIRST_STOP_UNDER_POSITION;
            $description = 'need increase support size';
        } elseif ($this->needKeepSupportSize()) {
            $supportStrategy = StopCreate::UNDER_POSITION;
            $description = 'need keep support size';
        }

        return new HedgeStrategy($supportStrategy, $mainPositionStrategy, $description);
    }

    private function getSupportRate(): float
    {
        return $this->supportPosition->size / $this->mainPosition->size;
    }
}
