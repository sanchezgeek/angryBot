<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Strategy;

use App\Bot\Application\Service\Strategy\Hedge\HedgeOppositeStopCreate;

final class HedgeStrategy
{
    public HedgeOppositeStopCreate $supportPositionOppositeStopCreation;
    public HedgeOppositeStopCreate $mainPositionOppositeStopCreation;
    public ?string $description;

    public function __construct(
        string $hedgeSupportPositionCreateOppositeStopAfter,
        string $hedgeMainPositionCreateOppositeStopAfter,
        ?string $description = null
    ) {
        $this->supportPositionOppositeStopCreation = HedgeOppositeStopCreate::tryFrom($hedgeSupportPositionCreateOppositeStopAfter);
        $this->mainPositionOppositeStopCreation = HedgeOppositeStopCreate::tryFrom($hedgeMainPositionCreateOppositeStopAfter);

        $this->description = $description;
    }
}
