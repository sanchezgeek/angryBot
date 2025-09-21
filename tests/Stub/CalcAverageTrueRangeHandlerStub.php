<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Application\Contract\CalcAverageTrueRangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\CalcAverageTrueRange;
use App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange\CalcAverageTrueRangeResult;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use RuntimeException;

final class CalcAverageTrueRangeHandlerStub implements CalcAverageTrueRangeHandlerInterface
{
    private array $data = [];

    public function addItem(
        CalcAverageTrueRange $entry,
        float $atrAbsolute,
        Percent $percentResult,
    ): void {
        $this->data[] = [
            'entry' => $entry,
            'result' => new CalcAverageTrueRangeResult(
                new AveragePriceChange($entry->timeframe, $entry->period, $atrAbsolute, $percentResult)
            ),
        ];
    }

    public function handle(CalcAverageTrueRange $entry): CalcAverageTrueRangeResult
    {
        foreach ($this->data as $item) {
            if ($item['entry'] == $entry) {
                return $item['result'];
            }
        }

        throw new RuntimeException('Cannot find mocked result for entry');
    }
}
