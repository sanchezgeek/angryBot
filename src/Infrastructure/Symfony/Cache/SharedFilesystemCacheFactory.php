<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Cache;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;

readonly class SharedFilesystemCacheFactory
{
    public function create(string $namespace, string $path): FilesystemAdapter
    {
        return new FilesystemAdapter(namespace: $namespace, directory: $path);
    }
}
