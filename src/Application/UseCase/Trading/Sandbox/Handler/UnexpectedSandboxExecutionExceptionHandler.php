<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Handler;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Helper\OutputHelper;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;

final readonly class UnexpectedSandboxExecutionExceptionHandler
{
    public function __construct(
        private RateLimiterFactory $unexpectedSandboxExceptionWhileCheckThrottlingLimiter,
        private AppErrorLoggerInterface $appErrorLogger,
    ) {
    }

    /**
     * @throws UnexpectedSandboxExecutionException
     */
    public function handle(object $caller, Throwable $e, SandboxStopOrder|SandboxBuyOrder $order): void
    {
        $message = sprintf(
            '[%s] Got "%s" error while processing %s order in sandbox%s',
            OutputHelper::shortClassName($caller),
            $e->getMessage(),
            get_class($order),
            $order->sourceOrder ? sprintf(' (id = %d)', $order->sourceOrder->getId()) : ''
        );

        $identifier = sprintf('%s_%s_%s', OutputHelper::shortClassName($order), $order->symbol->name(), $order->positionSide->value);
        if ($order->sourceOrder) {
            $identifier .= sprintf('_id_%d', $order->sourceOrder->getId());
        }

        if ($this->unexpectedSandboxExceptionWhileCheckThrottlingLimiter->create($identifier)->consume()->isAccepted()) {
            $this->appErrorLogger->exception($e);
        }

        throw new UnexpectedSandboxExecutionException($message, 0, $e);
    }
}
