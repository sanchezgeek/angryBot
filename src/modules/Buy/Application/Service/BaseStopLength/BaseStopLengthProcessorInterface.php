<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\BaseStopLength;

use App\Bot\Domain\Entity\BuyOrder;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('buy.createStopsAfterBuy.baseStopLength.processor')]
interface BaseStopLengthProcessorInterface
{
    public function supports(BuyOrder $buyOrder): bool;

    /**
     * @return float Delta between BuyOrder::price and further created Stop
     */
    public function process(BuyOrder $buyOrder): float;
}
