<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Helper\OutputHelper;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Exception\ReferencedPositionNotFound;
use App\Trading\SDK\Check\Exception\TooManyTriesForCheck;
use App\Trading\SDK\Check\Result\CommonOrderCheckFailureEnum;

final readonly class StopChecksChain
{
    /** @var TradingCheckInterface[] */
    private array $checks;

    public function __construct(
        private PositionServiceInterface $positionService,
        private AppErrorLoggerInterface $appErrorLogger,
        TradingCheckInterface ...$checks
    ) {
        $this->checks = $checks;
    }

    public function check(Stop $stop, TradingCheckContext $context): TradingCheckResult
    {
        $results = [];
        foreach ($this->checks as $check) {
            $dto = new StopCheckDto($stop, $context->ticker);

            try {
                if (!$check->supports($dto, $context)) {
                    continue;
                }
            } catch (ReferencedPositionNotFound $e) {
                return TradingCheckResult::failed($check, CommonOrderCheckFailureEnum::ReferencedPositionNotFound, $e->getMessage(), true); // quiet
            }

            $currentPositionState = $context->currentPositionState ?? $this->positionService->getPosition($stop->getSymbol(), $stop->getPositionSide());
            if (
                $currentPositionState->isPositionWithoutHedge() || $currentPositionState->isMainPosition()
                && !$check instanceof MainPositionStopCheckInterface
            ) {
                $this->appErrorLogger->error(sprintf('Check "%s" must implement %s', OutputHelper::shortClassName($check), MainPositionStopCheckInterface::class));
                continue;
            }

            try {
                $result = $check->check($dto, $context);
            } catch (TooManyTriesForCheck) {
                // @todo figure out what happened for main position stop check
                // will check MainPositionStopCheckInterface prevent it?
                return TradingCheckResult::failed($check, CommonOrderCheckFailureEnum::TooManyTries, 'Too many tries => no result => order must not be executed', true); // quiet
            } catch (UnexpectedSandboxExecutionException $e) {
                // @todo and here
                //       if execution goes here than here also must be check of MainPositionStopCheckInterface
                // @todo but anyway exception must be caught (push handler must live...)
                return TradingCheckResult::failed(
                    $check,
                    CommonOrderCheckFailureEnum::UnexpectedSandboxExecutionExceptionThrown,
                    sprintf('sandbox error (%s)', $e->getMessage()),
                    true // quiet
                );
            }

            if (!$result->success) {
                return $result;
            }

            $results[] = $result;
        }

        $innerInfo = $results
            ? implode(' ,;, ', array_map(static fn(AbstractTradingCheckResult $result) => $result->info(), $results))
            : 'no supported checks'
        ;

        return TradingCheckResult::succeed(sprintf('StopChecksChain for stop.id=%s', $stop->getId()), $innerInfo, !$results);
    }
}
