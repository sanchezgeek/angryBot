<?php

declare(strict_types=1);

namespace App\Info\Application\Service;

use App\Info\Contract\Dto\AbstractDependencyInfo;

final class DependencyInfoCollector
{
    /**
     * @var AbstractDependencyInfo[]
     */
    private array $info = [];

    public function addInfo(AbstractDependencyInfo $info): void
    {
        $this->info[] = $info;
    }

    public function getInfo(): array
    {
        return $this->info;
    }
}
