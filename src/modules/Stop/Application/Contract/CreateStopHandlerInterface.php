<?php

declare(strict_types=1);

namespace App\Stop\Application\Contract;

use App\Bot\Domain\Entity\Stop;
use App\Stop\Application\Contract\Command\CreateStop;

interface CreateStopHandlerInterface
{
    public function __invoke(CreateStop $command): Stop;
}
