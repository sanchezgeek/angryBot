<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Strategy;

final class SelectedStrategy
{
    public readonly HedgeOppositeStopCreate $hedgeOppositeStopCreate;

    public function __construct(
        string $createHedgeOppositeStopAfter
    ) {
        $this->hedgeOppositeStopCreate = HedgeOppositeStopCreate::tryFrom($createHedgeOppositeStopAfter);
    }
}
