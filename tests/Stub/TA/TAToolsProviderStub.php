<?php

declare(strict_types=1);

namespace App\Tests\Stub\TA;

use App\Domain\Trading\Enum\TimeFrame;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\TechnicalAnalysis\Application\Service\TechnicalAnalysisToolsInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

final class TAToolsProviderStub implements TAToolsProviderInterface
{
    /** @var array<string, TechnicalAnalysisToolsStub> */
    private array $taStubs = [];

    public function create(
        SymbolInterface $symbol,
        ?TimeFrame $interval = null
    ): TechnicalAnalysisToolsInterface {
        foreach ($this->taStubs as $item) {
            if (
                $item->symbol->name() === $symbol->name()
                && (!$interval || $item->interval === $interval)
            ) {
                return $item;
            }
        }

        throw new RuntimeException(
            sprintf('Cannot find mocked TechnicalAnalysisToolsInterface for symbol = %s and interval = %s', $symbol->name(), $interval->value)
        );
    }

    public function addTechnicalAnalysisTools(TechnicalAnalysisToolsInterface $taTools): self
    {
        $this->taStubs[] = $taTools;

        return $this;
    }

    public function mockedTaTools(SymbolInterface $symbol, TimeFrame $interval): TechnicalAnalysisToolsStub
    {
        $key = sprintf('%s_%s', $symbol->name(), $interval->value);

        return $this->taStubs[$key] ?? $this->taStubs[$key] = new TechnicalAnalysisToolsStub($symbol, $interval);
    }
}
