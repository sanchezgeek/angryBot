<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

use App\Bot\Domain\Entity\Stop;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopChecksContext;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Exception\TooManyTriesForCheckStop;

final class StopChecksChain
{
    /** @var StopCheckInterface[] */
    private array $checks;

    public function __construct(StopCheckInterface ...$checks)
    {
        $this->checks = $checks;
    }

    public function check(Stop $stop, StopChecksContext $context): StopCheckResult
    {
        foreach ($this->checks as $check) {
            if (!$check->supports($stop, $context)) {
                continue;
            }

            try {
                $result = $check->check($stop, $context);
            } catch (TooManyTriesForCheckStop) {
                return StopCheckResult::negative(get_class($check)); // order must not be executed
            }

            if (!$result->success) {
                return $result;
            }
        }

        return StopCheckResult::positive();
    }
}
