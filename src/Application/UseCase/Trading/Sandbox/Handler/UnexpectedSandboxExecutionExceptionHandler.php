<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Handler;

use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Application\Logger\AppErrorLoggerInterface;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Helper\OutputHelper;
use Throwable;

final readonly class UnexpectedSandboxExecutionExceptionHandler
{
    public const int SECONDS_INTERVAL_BETWEEN_SYMBOL_AND_POSITION_SIDE_LOG = 600;

    public function __construct(
        private AttemptLimitCheckerProviderInterface $attemptLimitCheckerProvider,
        private AppErrorLoggerInterface $appErrorLogger,
    ) {
    }

    /**
     * @throws UnexpectedSandboxExecutionException
     */
    public function handle(object $caller, Throwable $e, SandboxStopOrder|SandboxBuyOrder $order): void
    {
        $alias = $order instanceof SandboxStopOrder ? 's' : 'b';

        $message = sprintf(
            '[%s] Got "%s" error while processing %s order in sandbox%s',
            OutputHelper::shortClassName($caller),
            $e->getMessage(),
            get_class($order),
            $order->sourceOrder ? sprintf(' (%s.id = %d)', $alias, $order->sourceOrder->getId()) : ''
        );

        $identifier = sprintf('sandboxError_appError_logging_%s_%s_%s', OutputHelper::shortClassName($order), $order->symbol->name(), $order->positionSide->value);
//        if ($order->sourceOrder) {
//            $identifier .= sprintf('_id_%d', $order->sourceOrder->getId());
//        }

        if ($this->attemptLimitCheckerProvider->get($identifier, self::SECONDS_INTERVAL_BETWEEN_SYMBOL_AND_POSITION_SIDE_LOG)->attemptIsAvailable()) {
            $this->appErrorLogger->exception($e);
        }

        throw new UnexpectedSandboxExecutionException($message, 0, $e);
    }
}
