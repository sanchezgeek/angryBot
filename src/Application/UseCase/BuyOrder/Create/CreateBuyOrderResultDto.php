<?php

declare(strict_types=1);

namespace App\Application\UseCase\BuyOrder\Create;

use App\Bot\Domain\Entity\BuyOrder;

final class CreateBuyOrderResultDto
{
    public function __construct(public BuyOrder $buyOrder)
    {
    }
}
