<?php

declare(strict_types=1);

namespace App\Screener\Application\UseCase\FindAveragePriceChange;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\Trading\Application\Symbol\SymbolProvider;
use InvalidArgumentException;

final readonly class FindAveragePriceChangeEntryEvaluationProvider implements ParameterDefaultValueProviderInterface
{
    public function __construct(
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function getRequiredKeys(): array
    {
        return [
            'symbol',
            'interval',
            'intervalsCount',
        ];
    }

    public function get(array $input): FindAveragePriceChangeEntry
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('$symbol must be specified');
        }

        if (!$input['interval']) {
            throw new InvalidArgumentException('$interval must be specified');
        }

        if (!$input['intervalsCount']) {
            throw new InvalidArgumentException('$intervalsCount must be specified');
        }

        return new FindAveragePriceChangeEntry(
            $this->symbolProvider->getOrInitialize($input['symbol']),
            CandleIntervalEnum::from($input['interval']),
            (int)$input['intervalsCount']
        );
    }
}
