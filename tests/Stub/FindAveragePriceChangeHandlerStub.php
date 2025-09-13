<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Application\Contract\FindAveragePriceChangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\FindAveragePriceChange;
use App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange\FindAveragePriceChangeResult;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use RuntimeException;

final class FindAveragePriceChangeHandlerStub implements FindAveragePriceChangeHandlerInterface
{
    private array $data = [];

    public function addItem(
        FindAveragePriceChange $entry,
        Percent $percentResult,
        float $absoluteResult
    ): void {
        $this->data[] = [
            'entry' => $entry,
            'result' => new FindAveragePriceChangeResult(
                new AveragePriceChange($entry->averageOnInterval, $entry->intervalsCount, $absoluteResult, $percentResult)
            ),
        ];
    }

    public function handle(FindAveragePriceChange $entry): FindAveragePriceChangeResult
    {
        foreach ($this->data as $item) {
            if ($item['entry'] == $entry) {
                return $item['result'];
            }
        }

        throw new RuntimeException('Cannot find mocked result for entry');
    }
}
