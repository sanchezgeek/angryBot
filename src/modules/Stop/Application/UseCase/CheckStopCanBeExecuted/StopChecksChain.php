<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Bot\Domain\Entity\Stop;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\AbstractStopCheck;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopChecksContext;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Exception\TooManyTriesForCheckStop;

final class StopChecksChain
{
    /** @var AbstractStopCheck[] */
    private array $checks;

    public function __construct(
        private readonly AppErrorLoggerInterface $appErrorLogger,
        StopCheckInterface ...$checks
    ) {
        $this->checks = $checks;
    }

    public function check(Stop $stop, StopChecksContext $context): StopCheckResult
    {
        $results = [];
        foreach ($this->checks as $check) {
            if (!$check->supports($stop, $context)) {
                continue;
            }

            try {
                $result = $check->check($stop, $context);
            } catch (TooManyTriesForCheckStop) {
                return $check::negativeResult(); // order must not be executed (but without reason)
            } catch (UnexpectedSandboxExecutionException $e) {
                $this->appErrorLogger->exception($e);
                return $check::negativeResult($e->getMessage()); // order must not be executed (with reason)
            }

            if (!$result->success) {
                return $result;
            }

            $results[] = $result;
        }

        $reason = implode(' ,;, ', array_map(static fn(StopCheckResult $result) => $result->description(), $results));

        return StopCheckResult::positive(sprintf('StopChecksChain for stop.id=%s', $stop->getId()), $reason);
    }
}
