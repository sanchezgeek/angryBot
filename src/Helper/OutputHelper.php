<?php

declare(strict_types=1);

namespace App\Helper;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Worker\AppContext;
use JsonSerializable;

use function is_array;
use function is_object;
use function json_encode;
use function sprintf;
use function var_dump;

class OutputHelper
{
    public static function positionStats(string $desc, Position|CalcPositionLiquidationPriceResult $res): void
    {
        $entryPrice = $res instanceof Position ? $res->entryPrice : $res->positionEntryPrice()->value();
        $liquidationPrice = $res instanceof Position ? $res->liquidationPrice : $res->estimatedLiquidationPrice()->value();
        $liquidationDistance = $res instanceof Position ? $res->liquidationDistance() : $res->liquidationDistance();

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
            echo("\n---------------------------------------\n");
        }
    }

    public static function print(mixed ...$data): void
    {
        foreach ($data as $item) {
            if (is_array($item) || $item instanceof JsonSerializable || is_object($item)) {
                echo json_encode($item, JSON_PRETTY_PRINT);
                return;
            }

            echo $item . PHP_EOL;
        }
    }

    public static function warning(string $message): void
    {
        echo sprintf('@ %s', $message) . PHP_EOL;
    }

    public static function success(string $message): void
    {
        echo sprintf('+ %s', $message) . PHP_EOL;
    }

    public static function failed(string $message): void
    {
        echo sprintf('! %s', $message) . PHP_EOL;
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

    public static function ordersDebug(array $orders, bool $print = false): array
    {
        $map = array_map(
            static fn(Stop|BuyOrder $order) => sprintf(
                '%s / %s | %s%s, %s',
                $order->getPrice(),
                $order->getVolume(),
                $order instanceof Stop ? 's.' : 'b.',
                $order->getId(),
                $order->getSymbol()->value,
            ),
            $orders
        );

        if ($print) {
            var_dump($map);
        }

        return $map;
    }

    public static function shortClassName(string|object $className): string
    {
        $className = is_string($className) ? $className : get_class($className);

        $methodName = null;
        if (str_contains($className, '::')) {
            $parts = explode('::', $className);
            $className = $parts[0];
            $methodName = $parts[1];
        }

        $class = explode('\\', $className);

        return end($class) . ($methodName ? '::' . $methodName : '');
    }

    public static function currentTimePoint(): float
    {
        return microtime(true);
    }

    public static function printTimeDiff(string $desc, float $start): void
    {
        self::print(self::timeDiff($desc, $start));
    }

    public static function timeDiff(string $desc, float $start): string
    {
        $end = microtime(true);

        return sprintf('~~~ %s time diff: %s ~~~', $desc, $end - $start);
    }
}
