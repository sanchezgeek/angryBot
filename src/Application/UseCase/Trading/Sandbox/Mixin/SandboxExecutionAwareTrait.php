<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Mixin;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Helper\OutputHelper;
use Throwable;

trait SandboxExecutionAwareTrait
{
    // @todo handle it (stop | buy)
    /**
     * @throws UnexpectedSandboxExecutionException
     */
    public static function processSandboxExecutionException(Throwable $e, SandboxStopOrder|SandboxBuyOrder $order): void
    {
        $message = sprintf(
            '[%s] Got "%s" error while processing %s order in sandbox%s',
            OutputHelper::shortClassName(static::class),
            $e->getMessage(),
            get_class($order),
            $order->sourceOrder ? sprintf(' (id = %d)', $order->sourceOrder->getId()) : ''
        );

        throw new UnexpectedSandboxExecutionException($message, 0, $e);
    }
}
