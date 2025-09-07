<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\GetInstrumentAge;

use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\TechnicalAnalysis\Application\Contract\Query\GetInstrumentAge;
use App\Trading\Application\Symbol\SymbolProvider;
use InvalidArgumentException;

final readonly class GetInstrumentAgeEntryEvaluationProvider implements ParameterDefaultValueProviderInterface
{
    public function __construct(
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function getRequiredKeys(): array
    {
        return ['symbol' => 'symbol'];
    }

    public function get(array $input): GetInstrumentAge
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('$symbol must be specified');
        }

        $symbol = $this->symbolProvider->getOrInitialize($input['symbol']);

        return new GetInstrumentAge($symbol);
    }
}
