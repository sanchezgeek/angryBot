<?php

declare(strict_types=1);

namespace App\Trading\Domain\Grid\Definition;

use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
use App\Domain\Value\Percent\Percent;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use BackedEnum;
use InvalidArgumentException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

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
//        $pattern = '/^([-\d]+(?:\.\d+)?%)|([-\w]+)\.\.([-\d]+(?:\.\d+)?%)|([-\w]+)\|\d+%(?:\|\d+)?(?:\|[,\w=%]+)?$/';
        $pattern = '/^(.*)\.\.(.*)\|\d+%(?:\|\d+)?(?:\|[,\w=%]+)?$/';

        if (!preg_match($pattern, $definition)) {
            throw new InvalidArgumentException(
                sprintf('Invalid definition "%s" ("%s" expected, e.g.: `-very-short..-short|30%%|5|wOO|aF`)', $definition, $pattern),
            );
        }

        $arr = explode('|', $definition);
        $range = array_shift($arr);
        [$from, $to] = explode('..', $range);

        $fromPnlPercent = $this->parseDistanceSelector($symbol, $from);
        $toPnlPercent = $this->parseDistanceSelector($symbol, $to);

        return sprintf('%.2f%%..%.2f%%|%s', $fromPnlPercent, $toPnlPercent, implode('|', $arr));
    }

    private function parseDistanceSelector(SymbolInterface $symbol, string $distance): float
    {
        $expressionLanguage = new ExpressionLanguage();
        $expressionLanguage->register('parse_custom', function ($str) {
            return sprintf('parse_custom(%s)', $str);
        }, function ($arguments, $str) {
            preg_match_all('/[+-]?(?:' . self::allLengthsExpr() . '|[\d\.]+%?)/', $str, $matches);
            return $matches[0];
        });

        $parts = $expressionLanguage->evaluate(sprintf('parse_custom("%s")', $distance));

        $replacements = [];

        foreach ($parts as $key => $part) {
            if (str_starts_with($part, '-') || str_starts_with($part, '+')) {
                $part = substr($part, 1);
            }

            $value = match(true) {
                is_numeric($part) => $part,
                ($parsedPercent = self::parseSimplePercent($part)) !== null => $parsedPercent,
                ($lengthParsed = Length::tryFrom($part)) !== null => $this->getBoundPnlPercent($symbol, $lengthParsed),
                default => throw new InvalidArgumentException(sprintf('Operand must be of type %s enum cases, float/int or percent ("%s" given [from "%s"])', Length::class, $part, $distance)),
            };

            if ($part !== $value) {
                $replacements[$part] = (string)$value;
            }
        }

        $replacements = array_flip($replacements);
        uasort($replacements, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $replacements = array_flip($replacements);

        foreach ($replacements as $search => $replacement) {
            $distance = str_replace($search, (string)$replacement, $distance);
        }

        return $expressionLanguage->evaluate($distance);
    }

    private static function parseSimplePercent(string $definition): ?float
    {
        if (!str_ends_with($definition, '%') || (!is_numeric(substr($definition, 0, -1)))) {
            return null;
        }

        return (float)substr($definition, 0, -1);
    }

    private function getBoundPnlPercent(SymbolInterface $symbol, Length $lengthSelector): float
    {
        $priceChangePercent = $this->parameters->transformLengthToPricePercent($symbol, $lengthSelector)->value();

        return PnlHelper::transformPriceChangeToPnlPercent($priceChangePercent);
    }

    private static function allLengthsExpr(): string
    {
        $cases = array_map(static fn (BackedEnum $enum) => $enum->value, Length::cases());

        return implode('|', $cases);
    }
}
