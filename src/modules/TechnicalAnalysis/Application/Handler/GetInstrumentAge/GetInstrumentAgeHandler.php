<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\GetInstrumentAge;

use App\Domain\Trading\Enum\TimeFrame;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\TechnicalAnalysis\Application\Cache\TechnicalAnalysisSharedCache;
use App\TechnicalAnalysis\Application\Contract\Query\GetInstrumentAge;
use App\TechnicalAnalysis\Application\Contract\Query\GetInstrumentAgeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\GetInstrumentAgeResult;
use App\TechnicalAnalysis\Application\Service\Candles\PreviousCandlesProvider;
use DateTimeImmutable;

final readonly class GetInstrumentAgeHandler implements GetInstrumentAgeHandlerInterface, AppDynamicParametersProviderInterface
{
    #[AppDynamicParameter(group: 'ta', name: 'age')]
    public function handle(
        #[AppDynamicParameterEvaluations(defaultValueProvider: GetInstrumentAgeEntryEvaluationProvider::class, skipUserInput: true)]
        GetInstrumentAge $entry
    ): GetInstrumentAgeResult {
        $symbol = $entry->symbol;

        $birthday = $this->cache->get(sprintf('instrument_birthday_%s', $symbol->name()), fn () => $this->getFirstDatetime($entry), 9999999);

        return new GetInstrumentAgeResult($symbol, $birthday, date_create_immutable());
    }

    private function getFirstDatetime(GetInstrumentAge $entry): DateTimeImmutable
    {
        $symbol = $entry->symbol;

        $candles = $this->candlesProvider->getPreviousCandles($symbol, TimeFrame::M1, 1000);
        $first = $candles[array_key_first($candles)];

        return (new DateTimeImmutable)->setTimestamp($first->time);
    }

    public function __construct(
        #[AppDynamicParameterAutowiredArgument]
        private PreviousCandlesProvider $candlesProvider,

        #[AppDynamicParameterAutowiredArgument]
        private TechnicalAnalysisSharedCache $cache,
    ) {
    }
}
