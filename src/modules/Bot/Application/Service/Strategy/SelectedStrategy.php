<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Strategy;

use App\Bot\Application\Service\Strategy\Hedge\HedgeOppositeStopCreate;

final class SelectedStrategy
{
    public HedgeOppositeStopCreate $hedgeSupportPositionOppositeStopCreation;
    public HedgeOppositeStopCreate $hedgeMainPositionOppositeStopCreation;

    public function __construct(
        string $hedgeSupportPositionCreateOppositeStopAfter,
        string $hedgeMainPositionCreateOppositeStopAfter
    ) {
        $this->hedgeSupportPositionOppositeStopCreation = HedgeOppositeStopCreate::tryFrom($hedgeSupportPositionCreateOppositeStopAfter);
        $this->hedgeMainPositionOppositeStopCreation = HedgeOppositeStopCreate::tryFrom($hedgeMainPositionCreateOppositeStopAfter);
    }
}
