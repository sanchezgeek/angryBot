<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Strategy;

use App\Bot\Application\Service\Strategy\Hedge\OppositeStopCreate;

final class HedgeStrategy
{
    public OppositeStopCreate $supportPositionOppositeStopCreation;
    public OppositeStopCreate $mainPositionOppositeStopCreation;
    public ?string $description;

    public function __construct(
        string $hedgeSupportPositionCreateOppositeStopAfter,
        string $hedgeMainPositionCreateOppositeStopAfter,
        ?string $description = null
    ) {
        $this->supportPositionOppositeStopCreation = OppositeStopCreate::tryFrom($hedgeSupportPositionCreateOppositeStopAfter);
        $this->mainPositionOppositeStopCreation = OppositeStopCreate::tryFrom($hedgeMainPositionCreateOppositeStopAfter);

        $this->description = $description;
    }
}
