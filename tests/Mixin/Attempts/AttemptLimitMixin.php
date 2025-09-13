<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Attempts;

use App\Application\AttemptsLimit\AttemptLimitCheckerInterface;
use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;

trait AttemptLimitMixin
{
    protected function attemptsCheckerProviderWithAllowedAttempts(): AttemptLimitCheckerProviderInterface
    {
        $checkerMock = $this->createMock(AttemptLimitCheckerInterface::class);
        $checkerMock->method('attemptIsAvailable')->willReturn(true);

        $providerMock = $this->createMock(AttemptLimitCheckerProviderInterface::class);
        $providerMock->method('get')->willReturn($checkerMock);

        return $providerMock;
    }
}
