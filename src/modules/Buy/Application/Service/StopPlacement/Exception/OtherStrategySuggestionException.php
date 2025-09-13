<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\StopPlacement\Exception;

use App\Buy\Application\StopPlacementStrategy;

final class OtherStrategySuggestionException extends \Exception
{
    public function __construct(
        public StopPlacementStrategy $tier,
        public StopPlacementStrategy $suggestion
    ) {
        parent::__construct(sprintf('"%s" strategy suggested (from %s)', $this->suggestion->name, $this->tier->name));
    }
}
