<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Application\Service\Strategy\Hedge\HedgeOppositeStopCreate;
use App\Bot\Application\Service\Strategy\HedgeStrategy;
use App\Bot\Domain\Position;

final class Hedge
{
    public function __construct(
        public readonly Position $mainPosition,
        public readonly Position $supportPosition,
    ) {
    }

    public function isSupportPosition(Position $position): bool
    {
        return $this->supportPosition->side === $position->side;
    }

    public function getSupportRate(): float
    {
        $rate = $this->supportPosition->size / $this->mainPosition->size;

        return $rate;
    }

    public function needIncreaseSupport(): bool
    {
        $rate = $this->getSupportRate();

        return $rate <= 0.38;
    }

    /**
     * PushRelevantBuyOrdersHandler will create small stops with `under_position`
     */
    public function needKeepSupportSize(): bool
    {
        $rate = $this->getSupportRate();

        return $rate > 0.38 && $rate < 0.45;
    }

    public function getHedgeStrategy(): HedgeStrategy
    {
        $mainPositionStrategy = HedgeOppositeStopCreate::AFTER_FIRST_POSITION_STOP->value;
        $supportStrategy = HedgeOppositeStopCreate::DEFAULT_STOP_STRATEGY->value;
        $description = null;

        if ($this->needIncreaseSupport()) {
            $supportStrategy = HedgeOppositeStopCreate::AFTER_FIRST_POSITION_STOP->value;
            $description = 'needKeepSupportSize';
        } elseif ($this->needKeepSupportSize()) {
            $supportStrategy = HedgeOppositeStopCreate::UNDER_POSITION->value;
            $description = 'needKeepSupportSize';
        }

        return new HedgeStrategy($supportStrategy, $mainPositionStrategy, $description);
    }

    public static function create(Position $a, Position $b): self
    {
        if ($a->side === $b->side) {
            throw new \LogicException('Positions on the same side');
        }

        if ($a->size > $b->size) {
            $mainPosition = $a; $supportPosition = $b;
        } else {
            $mainPosition = $b; $supportPosition = $a;
        }

        return new Hedge($mainPosition, $supportPosition);
    }
}
