<?php

declare(strict_types=1);

namespace App\Helper;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Worker\AppContext;
use DateTimeImmutable;
use JsonSerializable;

use function is_array;
use function is_object;
use function json_encode;
use function sprintf;
use function var_dump;

class OutputHelper
{
    public static function getPrettyUnescaped(mixed $data, bool $print = true): string
    {
        $data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($print) {
            self::print($data);
        }

        return $data;
    }

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

    private static function currentDateTime(): string
    {
        return new DateTimeImmutable()->format('m/d H:i:s');
    }

    public static function warning(string $message): void
    {
        echo sprintf('@ [%s] %s', self::currentDateTime(), $message) . PHP_EOL;
    }

    public static function success(string $message): void
    {
        echo sprintf('+ [%s] %s', self::currentDateTime(), $message) . PHP_EOL;
    }

    public static function failed(string $message): void
    {
        echo sprintf('! [%s] %s', self::currentDateTime(), $message) . PHP_EOL;
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
                $order->getSymbol()->name(),
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
        self::print(
            sprintf('   ~~~ %s time diff: %.3f ~~~', $desc, self::timeDiff($start))
        );
    }

    public static function timeDiff(float $start): float
    {
        $end = microtime(true);

        return $end - $start;
    }

    public static function linkToSymbolDashboard(SymbolInterface $symbol, ?string $caption = null): string
    {
        $url = self::urlToSymbolDashboard($symbol);
        $caption = $caption ?? $symbol->name();

        return sprintf('<href=%s>%s</>', $url, $caption);
    }

    public static function urlToSymbolDashboard(SymbolInterface $symbol): string
    {
        $host = $_ENV['SERVER_NAME'];
        $port = $_ENV['HTTPS_PORT'];

        return sprintf('https://%s:%s/admin/dashboard/symbol-page/%s', $host, $port, $symbol->name());
    }
}
