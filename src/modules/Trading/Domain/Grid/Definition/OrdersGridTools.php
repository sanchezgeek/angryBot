<?php

declare(strict_types=1);

namespace App\Trading\Domain\Grid\Definition;

use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
use App\Domain\Value\Percent\Percent;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use App\Trading\Domain\Symbol\SymbolInterface;
use InvalidArgumentException;
use Throwable;

/**
 * @see \App\Tests\Functional\Modules\Trading\Domain\Grid\Definition\OrdersGridToolsTest
 */
final readonly class OrdersGridTools
{
    public function __construct(
        private TradingParametersProviderInterface $parameters
    ) {
    }

    public static function makeRawGridDefinition(
        string|float|Percent $from,
        string|float|Percent $to,
        float|Percent $positionSizePartPercent,
        int $stopsCount,
        array $additionalContexts = []
    ): string {
        $from = match (true) {
            is_float($from) => sprintf('%s%%', $from),
            default => (string)$from
        };

        $to = match (true) {
            is_float($to) => sprintf('%s%%', $to),
            default => (string)$to
        };

        $result = sprintf('%s..%s|%d%%|%s', $from, $to, $positionSizePartPercent, $stopsCount);

        if ($additionalContexts) {
            $result .= sprintf('|%s', implode(',', $additionalContexts));
        }

        return $result;
    }

    public function transformToFinalPercentRangeDefinition(SymbolInterface $symbol, string $definition): string
    {
        $pattern = '/^([-\d]+(?:\.\d+)?%)|([-\w]+)\.\.([-\d]+(?:\.\d+)?%)|([-\w]+)\|\d+%(?:\|\d+)?(?:\|[,\w=%]+)?$/';

        if (!preg_match($pattern, $definition)) {
            throw new InvalidArgumentException(
                sprintf('Invalid definition "%s" ("%s" expected, e.g.: `-very-short..-short|30%%|5|wOO|aF`)', $definition, $pattern),
            );
        }

        $arr = explode('|', $definition);
        $range = array_shift($arr);
        [$from, $to] = explode('..', $range);

        if (!$fromPnlPercent = self::parsePercentFromRangeDefinition($from)) {
            try {
                $fromPnlPercent = $this->getPnlPercentFromLength($symbol, $from);
            } catch (Throwable $e) {
                throw new InvalidArgumentException(sprintf('Got %s while parse range definition `from`-clause', $e->getMessage()));
            }
        }

        if (!$toPnlPercent = self::parsePercentFromRangeDefinition($to)) {
            try {
                $toPnlPercent = $this->getPnlPercentFromLength($symbol, $to);
            } catch (Throwable $e) {
                throw new InvalidArgumentException(sprintf('Got %s while parse range definition `to`-clause', $e->getMessage()));
            }
        }

        return sprintf('%.2f%%..%.2f%%|%s', $fromPnlPercent, $toPnlPercent, implode('|', $arr));
    }

    private static function parsePercentFromRangeDefinition(string $definition): ?float
    {
        if (
            !str_ends_with($definition, '%')
            || (!is_numeric(substr($definition, 0, -1)))
        ) {
            return null;
        }

        return (float)substr($definition, 0, -1);
    }

    /**
     * @see OpenPositionStopsGridsDefinitions::parseFromPnlPercent DRY
     */
    private function getPnlPercentFromLength(SymbolInterface $symbol, string $length): float
    {
        [$distance, $sign] = self::parseDistanceSelector($length);

        return $sign * $this->getBoundPnlPercent($symbol, $distance);
    }

    private function getBoundPnlPercent(SymbolInterface $symbol, Length $lengthSelector): float
    {
        $priceChangePercent = $this->parameters->transformLengthToPricePercent($symbol, $lengthSelector)->value();

        return PnlHelper::transformPriceChangeToPnlPercent($priceChangePercent);
    }

    private static function parseDistanceSelector(string $distance): array
    {
        $sign = 1;
        if (str_starts_with($distance, '-')) {
            $sign = -1;
            $distance = substr($distance, 1);
        }

        if (!$parsed = PriceDistanceSelector::from($distance)) {
            throw new InvalidArgumentException(sprintf('distance must be of type %s enum cases', PriceDistanceSelector::class));
        }

        return [$parsed, $sign];
    }
}
