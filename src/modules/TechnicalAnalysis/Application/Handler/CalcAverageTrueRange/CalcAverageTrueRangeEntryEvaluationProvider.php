<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\TechnicalAnalysis\Application\Contract\Query\CalcAverageTrueRange;
use App\Trading\Application\Symbol\SymbolProvider;
use InvalidArgumentException;

final readonly class CalcAverageTrueRangeEntryEvaluationProvider implements ParameterDefaultValueProviderInterface
{
    private const array REQUIRED_KEYS = [
        'symbol',
        'interval',
        'intervalsBack',
    ];

    private const array DEFAULTS = [
        'interval' => '1D',
        'intervalsBack' => '7',
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

    public function get(array $input): CalcAverageTrueRange
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('$symbol must be specified');
        }

        if (!$input['interval']) {
            $input['interval'] = self::DEFAULTS['interval'];
        }

        if (!$input['intervalsBack']) {
            $input['intervalsBack'] = self::DEFAULTS['intervalsBack'];
        }

        $symbol = $this->symbolProvider->getOrInitialize($input['symbol']);
        $interval = CandleIntervalEnum::from($input['interval']);
        $intervalsBack = (int)$input['intervalsBack'];

        return new CalcAverageTrueRange($symbol, $interval, $intervalsBack);
    }
}
