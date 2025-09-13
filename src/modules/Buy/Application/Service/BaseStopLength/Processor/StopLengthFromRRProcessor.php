<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\BaseStopLength\Processor;

use App\Bot\Domain\Entity\BuyOrder;
use App\Buy\Application\Service\BaseStopLength\AbstractBaseStopLengthProcessor;
use App\Buy\Application\Service\BaseStopLength\BaseStopLengthProcessorInterface;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\RiskToRewardStopLength;
use RuntimeException;

final class StopLengthFromRRProcessor extends AbstractBaseStopLengthProcessor implements BaseStopLengthProcessorInterface
{
    public function supports(BuyOrder $buyOrder): bool
    {
        return $buyOrder->getStopCreationDefinition() instanceof RiskToRewardStopLength;
    }

    public function doProcess(BuyOrder $buyOrder): float
    {
        throw new RuntimeException('Not implemented yet');
    }
}
