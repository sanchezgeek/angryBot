<?php

declare(strict_types=1);

namespace App\Tests\Mixin\TA;

use App\Tests\Stub\TA\TAToolsProviderStub;

trait TaToolsProviderMocker
{
    protected TAToolsProviderStub $analysisToolsProviderStub;

    protected function initializeTaProviderStub(): TAToolsProviderStub
    {
        $this->analysisToolsProviderStub = new TAToolsProviderStub();

        return $this->analysisToolsProviderStub;
    }
}
