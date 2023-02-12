<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Strategy;

final class SelectedStrategy
{
    public readonly HedgeOppositeStopCreate $hedgeSupportPositionOppositeStopCreation;
    public readonly HedgeOppositeStopCreate $hedgeMainPositionOppositeStopCreation;

    public function __construct(
        string $hedgeSupportPositionCreateOppositeStopAfter,
        string $hedgeMainPositionCreateOppositeStopAfter
    ) {
        $this->hedgeSupportPositionOppositeStopCreation = HedgeOppositeStopCreate::tryFrom($hedgeSupportPositionCreateOppositeStopAfter);
        $this->hedgeMainPositionOppositeStopCreation = HedgeOppositeStopCreate::tryFrom($hedgeMainPositionCreateOppositeStopAfter);
    }
}
