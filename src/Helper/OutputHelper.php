<?php

declare(strict_types=1);

namespace App\Helper;

use App\Bot\Domain\Position;
use App\Worker\AppContext;

use function is_array;
use function json_encode;
use function sprintf;
use function var_dump;

class OutputHelper
{
    public static function positionStats(string $desc, Position $position): void
    {
        $liquidationDistance = FloatHelper::round($position->entryPrice - $position->liquidationPrice);

        OutputHelper::print(
            sprintf('%s | LiquidationDistance = %.2f', $desc, $liquidationDistance)
        );
    }

    public static function block(?string $desc, ...$data): void
    {
        if ($desc) {
            self::notice($desc);
        }

        self::print(...$data);

        if ($desc) {
            echo("---------------------------------------\n");
        }
    }

    public static function print(mixed ...$data): void
    {
        foreach ($data as $item) {
            if (is_array($item)) {
                echo json_encode($item, JSON_PRETTY_PRINT);
                return;
            }

            echo $item . PHP_EOL;
        }
    }

    public static function warning(string $message): void
    {
        var_dump(sprintf('@ %s', $message));
    }

    public static function printIfDebug(mixed $data): void
    {
        if (!AppContext::isDebug()) {
            return;
        }

        self::print($data);
    }

    public static function notice(string $message, bool $withLineBreak = true): void
    {
        echo PHP_EOL . '--------------- ' . $message . ' -------------';
        if ($withLineBreak) {
            echo PHP_EOL;
        }
    }
}