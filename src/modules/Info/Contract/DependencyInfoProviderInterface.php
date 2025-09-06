<?php

declare(strict_types=1);

namespace App\Info\Contract;

use App\Info\Application\Service\DependencyInfoCollector;
use App\Info\Contract\Dto\AbstractDependencyInfo;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('info.info_provider')]
interface DependencyInfoProviderInterface
{
    public function getDependencyInfo(): AbstractDependencyInfo;
//    public function registerInfo(DependencyInfoCollector $collector): void;
}
