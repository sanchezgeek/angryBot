<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\TechnicalAnalysis\Application\Service\TechnicalAnalysisTools;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

final class TAToolsProviderStub implements TAToolsProviderInterface
{
    /** @var array<array{symbol: SymbolInterface, onInterval:CandleIntervalEnum, tools: TechnicalAnalysisTools}> */
    private array $data = [];

    public function addItem(
        SymbolInterface $symbol,
        ?CandleIntervalEnum $candleIntervalEnum,
        TechnicalAnalysisTools $tools,
    ): void {
        $this->data[] = [
            'symbol' => $symbol,
            'onInterval' => $candleIntervalEnum,
            'tools' => $tools,
        ];
    }

    public function create(
        SymbolInterface $symbol,
        ?CandleIntervalEnum $candleIntervalEnum = null
    ): TechnicalAnalysisTools {
        foreach ($this->data as $item) {
            if (
                $item['symbol']->name() === $symbol->name()
                && $item['onInterval'] === $candleIntervalEnum
            ) {
                $tools = $item['tools'];

                if ($item['onInterval']) {
                    $tools->withInterval($item['onInterval']);
                }

                return $tools;
            }
        }

        throw new RuntimeException('Cannot find mocked tools');
    }
}
