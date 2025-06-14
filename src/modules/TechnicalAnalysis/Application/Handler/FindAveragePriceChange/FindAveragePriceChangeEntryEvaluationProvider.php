<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\TechnicalAnalysis\Application\Contract\Query\FindAveragePriceChange;
use App\Trading\Application\Symbol\SymbolProvider;
use InvalidArgumentException;

final readonly class FindAveragePriceChangeEntryEvaluationProvider implements ParameterDefaultValueProviderInterface
{
    private const array REQUIRED_KEYS = [
        'symbol',
        'interval',
        'intervalsCount',
        'includeCurrentInterval',
    ];

    private const array DEFAULTS = [
        'interval' => '1D',
        'intervalsCount' => '7',
        'includeCurrentInterval' => 'false',
    ];

    public function __construct(
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function getRequiredKeys(): array
    {
        $result = [];
        foreach (self::REQUIRED_KEYS as $name) {
            $caption = $name;
            if (isset(self::DEFAULTS[$name])) {
                $caption .= sprintf(' (default = `%s`)', self::DEFAULTS[$name]);
            }

            $result[$name] = $caption;
        }

        return $result;
    }

    public function get(array $input): FindAveragePriceChange
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('$symbol must be specified');
        }

        if (!$input['interval']) {
            $input['interval'] = self::DEFAULTS['interval'];
        }

        if (!$input['intervalsCount']) {
            $input['intervalsCount'] = self::DEFAULTS['intervalsCount'];
        }

        if (!isset($input['includeCurrentInterval'])) {
            $input['includeCurrentInterval'] = false;
        }

        $symbol = $this->symbolProvider->getOrInitialize($input['symbol']);
        $interval = CandleIntervalEnum::from($input['interval']);
        $intervalsCount = (int)$input['intervalsCount'];

        return match ($input['includeCurrentInterval']) {
            true => FindAveragePriceChange::includeCurrentInterval($symbol, $interval, $intervalsCount),
            false => FindAveragePriceChange::previousToCurrentInterval($symbol, $interval, $intervalsCount),
            default => throw new InvalidArgumentException(sprintf('For `includeCurrentInterval` select one of "true" or  "false" (%s provided)', $input['includeCurrentInterval'])),
        };
    }
}
