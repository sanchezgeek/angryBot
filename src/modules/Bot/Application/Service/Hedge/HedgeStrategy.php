<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Application\Service\Strategy\StopCreate;

final class HedgeStrategy
{
    public StopCreate $supportPositionOppositeStopCreation;
    public StopCreate $mainPositionOppositeStopCreation;
    public ?string $description;

    public function __construct(
        string $hedgeSupportPositionCreateOppositeStopAfter,
        string $hedgeMainPositionCreateOppositeStopAfter,
        ?string $description = null
    ) {
        $this->supportPositionOppositeStopCreation = StopCreate::tryFrom($hedgeSupportPositionCreateOppositeStopAfter);
        $this->mainPositionOppositeStopCreation = StopCreate::tryFrom($hedgeMainPositionCreateOppositeStopAfter);

        $this->description = $description;
    }
}
