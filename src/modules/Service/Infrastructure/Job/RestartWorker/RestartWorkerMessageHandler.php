<?php

declare(strict_types=1);

namespace App\Service\Infrastructure\Job\RestartWorker;

use App\Helper\OutputHelper;
use App\Worker\AppContext;
use JetBrains\PhpStorm\NoReturn;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RestartWorkerMessageHandler
{
    #[NoReturn] public function __invoke(RestartWorkerMessage $message): void
    {
        OutputHelper::print(sprintf('%s restarted', AppContext::runningWorker()->value));

        die;
    }
}
