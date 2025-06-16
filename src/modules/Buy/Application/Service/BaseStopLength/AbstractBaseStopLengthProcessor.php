<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\BaseStopLength;

use App\Bot\Domain\Entity\BuyOrder;
use App\Helper\OutputHelper;
use RuntimeException;

abstract class AbstractBaseStopLengthProcessor
{
    abstract public function supports(BuyOrder $buyOrder): bool;

    final public function process(BuyOrder $buyOrder): float
    {
        if (!$this->supports($buyOrder)) {
            throw new RuntimeException(
                sprintf(
                    '%s processor cannot process BuyOrder with id = %d (strategy was "%s")',
                    OutputHelper::shortClassName($this),
                    $buyOrder->getId(),
                    $buyOrder->getStopCreationDefinition()
                )
            );
        }

        return $this->doProcess($buyOrder);
    }

    abstract protected function doProcess(BuyOrder $buyOrder): float;
}
