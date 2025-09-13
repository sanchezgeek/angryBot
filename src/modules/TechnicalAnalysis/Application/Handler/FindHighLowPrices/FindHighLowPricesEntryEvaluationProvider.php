<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\FindHighLowPrices;

use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\TechnicalAnalysis\Application\Contract\Query\FindHighLowPrices;
use App\Trading\Application\Symbol\SymbolProvider;
use InvalidArgumentException;

final readonly class FindHighLowPricesEntryEvaluationProvider implements ParameterDefaultValueProviderInterface
{
    private const array REQUIRED_KEYS = [
        'symbol',
    ];

    public function __construct(
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function getRequiredKeys(): array
    {
        return ['symbol' => 'symbol'];
    }

    public function get(array $input): FindHighLowPrices
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('$symbol must be specified');
        }

        $symbol = $this->symbolProvider->getOrInitialize($input['symbol']);

        return new FindHighLowPrices($symbol);
    }
}
