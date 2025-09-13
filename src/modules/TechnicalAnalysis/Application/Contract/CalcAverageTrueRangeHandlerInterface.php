<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract;

use App\TechnicalAnalysis\Application\Contract\Query\CalcAverageTrueRange;
use App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange\CalcAverageTrueRangeResult;

interface CalcAverageTrueRangeHandlerInterface
{
    public function handle(CalcAverageTrueRange $entry): CalcAverageTrueRangeResult;
}
