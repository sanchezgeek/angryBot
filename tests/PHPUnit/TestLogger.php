<?php

declare(strict_types=1);

namespace App\Tests\PHPUnit;

use DateTimeInterface;
use Psr\Log\AbstractLogger;
use Stringable;

use function date;
use function error_log;
use function gettype;
use function is_callable;
use function is_object;
use function is_scalar;
use function sprintf;
use function str_contains;
use function str_replace;

class TestLogger extends AbstractLogger
{
    public static bool $printOnDestruct = false;

    /** @var mixed[] */
    public array $records = [];

    public function getRecordsByLevel(string $level): array
    {
        return array_filter($this->records, static fn(array $item) => $item['level'] === $level);
    }

    /**
     * @param mixed[] $context
     */
    public function log(mixed $level, Stringable | string $message, array $context = []): void
    {
        $record = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];

        $this->records[] = $record;
    }

    public function reset(): void
    {
        $this->records = [];
    }

    public function __destruct()
    {
        if (!self::$printOnDestruct) {
            $this->reset();

            return;
        }

        /**
         * @var int|string $level
         * @var string $message
         * @var mixed[] $context
         */
        foreach ($this->records as ['level' => $level, 'message' => $message, 'context' => $context]) {
            if (str_contains($message, '{')) {
                foreach ($context as $key => $val) {
                    if ($val === null || is_scalar($val) || (is_object($val) && is_callable([$val, '__toString']))) {
                        $message = str_replace("{{$key}}", (string) $val, $message);
                    } elseif ($val instanceof DateTimeInterface) {
                        $message = str_replace("{{$key}}", $val->format(DateTimeInterface::RFC3339), $message);
                    } elseif (is_object($val)) {
                        $message = str_replace("{{$key}}", '[object ' . $val::class . ']', $message);
                    } else {
                        $message = str_replace("{{$key}}", '[' . gettype($val) . ']', $message);
                    }
                }
            }

            /** @noinspection ForgottenDebugOutputInspection */
            error_log(sprintf('%s [%s] %s', date(DateTimeInterface::RFC3339), $level, $message));
        }
    }
}
