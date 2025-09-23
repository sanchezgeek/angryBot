<?php

declare(strict_types=1);

namespace App\Tests\Mixin\TA;

use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\Tests\Stub\TA\TAToolsProviderStub;
use RuntimeException;

trait TaToolsProviderMocker
{
    protected ?TAToolsProviderStub $analysisToolsProviderStub = null;

    protected function initializeTaProviderStub(): TAToolsProviderStub
    {
        $this->analysisToolsProviderStub = new TAToolsProviderStub();

        self::setTaToolsProviderStubInContainer($this->analysisToolsProviderStub);

        return $this->analysisToolsProviderStub;
    }

    public function getMockedTaToolsProviderStub(): TAToolsProviderStub
    {
        if (!$this->analysisToolsProviderStub) {
            throw new RuntimeException('Initialize stub first with ->initializeTaProviderStub');
        }

        return $this->analysisToolsProviderStub;
    }

    public static function setTaToolsProviderStubInContainer(TAToolsProviderInterface $provider): void
    {
        self::getContainer()->set(TAToolsProviderInterface::class, $provider);
    }
}
