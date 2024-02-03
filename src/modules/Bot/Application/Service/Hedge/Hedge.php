<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Domain\Position;
use App\Bot\Domain\Strategy\StopCreate;
use App\Domain\Value\Percent\Percent;

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

        // @todo | what if positions sizes are equals?
        return $a->size > $b->size ? new Hedge($a, $b) : new Hedge($b, $a);
    }

    public static function fromPosition(Position $position): ?self
    {
        if ($position->oppositePosition) {
            return self::create($position, $position->oppositePosition);
        }

        return null;
    }

    public function isSupportPosition(Position $position): bool
    {
        return $this->supportPosition->side === $position->side;
    }

    public function isMainPosition(Position $position): bool
    {
        return $this->mainPosition->side === $position->side;
    }

    /**
     * @todo | hedge | move to service (which must operate with some config)
     */
    public function needIncreaseSupport(): bool
    {
        $rate = $this->getSupportRate()->part();

        return $rate <= 0.28;
    }

    /**
     * PushBuyOrdersHandler will create small stops with `under_position`
     *
     * @todo | hedge | move to service
     */
    public function needKeepSupportSize(): bool
    {
        $rate = $this->getSupportRate()->part();

        return $rate < 0.35;
    }

    /**
     * @todo | hedge | move to service
     */
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

    public function getSupportRate(): Percent
    {
        return new Percent(
            ($this->supportPosition->size / $this->mainPosition->size) * 100
        );
    }
}
