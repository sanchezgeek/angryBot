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
            echo("---------------------------------------\n");
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

    public static function ordersDebug(array $orders): void
    {
        var_dump(array_map(static fn(Stop|BuyOrder $order) => $order->getPrice(), $orders));
    }

    public static function shortClassName(string $className): string
    {
        $methodName = null;
        if (str_contains($className, '::')) {
            $parts = explode('::', $className);
            $className = $parts[0];
            $methodName = $parts[1];
        }

        $class = explode('\\', $className);

        return end($class) . ($methodName ? '::' . $methodName : '');
    }
}
