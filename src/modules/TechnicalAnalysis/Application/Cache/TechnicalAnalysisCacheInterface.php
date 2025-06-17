<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Cache;

use App\Application\Cache\CacheServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('technicalAnalysis.cache')]
interface TechnicalAnalysisCacheInterface
{
    public function replaceInnerCacheService(CacheServiceInterface $cacheService): void;
}
