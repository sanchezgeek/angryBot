<?php

declare(strict_types=1);

namespace App\Stop\Application\Contract;

use App\Stop\Application\Contract\Command\CreateOppositeStopsAfterBuy;

interface CreateOppositeStopsAfterBuyCommandHandlerInterface
{
    public function __invoke(CreateOppositeStopsAfterBuy $command);
}
