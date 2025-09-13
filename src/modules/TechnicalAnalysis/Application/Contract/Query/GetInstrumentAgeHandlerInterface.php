<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract\Query;

interface GetInstrumentAgeHandlerInterface
{
    public function handle(GetInstrumentAge $entry): GetInstrumentAgeResult;
}
