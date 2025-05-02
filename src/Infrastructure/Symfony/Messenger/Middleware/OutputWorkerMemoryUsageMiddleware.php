<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Middleware;

use App\Clock\ClockInterface;
use App\Helper\OutputHelper;
use App\Worker\AppContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Throwable;

final class OutputWorkerMemoryUsageMiddleware implements MiddlewareInterface
{
    private const PRINT_AFTER_ITERATIONS_COUNT = 300;
    private int $iterationsCount = 1;

    public function __construct(private ClockInterface $clock)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            $this->doLog();
        } catch (Throwable) {}

        return $stack->next()->handle($envelope, $stack);
    }

    private function doLog(): void
    {
        if (!($_ENV['LOG_MEMORY'] ?? null)) {
            return;
        }

        $worker = AppContext::runningWorker();

        if (
            $worker && (
                $this->iterationsCount === 2
                || $this->iterationsCount % self::PRINT_AFTER_ITERATIONS_COUNT === 0
            )
        ) {
//            if ($worker === RunningWorker::BUY_ORDERS) {
//                memprof_dump_callgrind(fopen(__DIR__ . "/../../../../../profile.callgrind", "w"));
//                file_put_contents(__DIR__ . "/../../../../../profile.json", print_r(memprof_dump_array(), true));
//            }
            $info = ['worker' => AppContext::workerHash()];
            if ($symbol = $_ENV['PROCESSED_SYMBOL'] ?? null) {
                $info['symbol'] = $symbol;
            }

            if ($processedOrders = $_ENV['PROCESSED_ORDERS'] ?? null) {
                $info['processedOrders'] = $processedOrders;
            }

            preg_match('/^VmRSS:\s(.*)/m', file_get_contents('/proc/self/status'), $m);
            $info['memory'] = sprintf('%.2f KB / %s', memory_get_usage() / 1024, trim($m[1]));

            OutputHelper::print(sprintf('mem-info | %s | %s', $this->clock->now()->format('m-d H:i:s'), implode(', ', $info)));
        }

        $this->iterationsCount++;
    }
}

