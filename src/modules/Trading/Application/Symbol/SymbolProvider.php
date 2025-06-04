<?php

declare(strict_types=1);

namespace App\Trading\Application\Symbol;

use App\Trading\Application\Symbol\Exception\SymbolNotFoundException;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trading\Infrastructure\Cache\SymbolsCache;

final class SymbolProvider
{
    private const int CACHE_TTL = 86400;

    private array $hotCache = [];

    public function __construct(
        private readonly SymbolRepository $symbolRepository,
        private readonly SymbolsCache $cache
    ) {
    }

    /**
     * @throws SymbolNotFoundException
     */
    public function getOneByName(string $name): Symbol
    {
        if (isset($this->hotCache[$name])) {
            return $this->hotCache[$name];
        }

        return $this->hotCache[$name] = $this->cache->get(sprintf('symbols_%s', $name), function () use ($name) {
            if ($symbol = $this->symbolRepository->findOneByName($name)) return $symbol;
            throw new SymbolNotFoundException(sprintf('Cannot find symbol by "%s" name', $name));
        }, self::CACHE_TTL);
    }

    /**
     * @throws SymbolNotFoundException
     */
    public function replaceEnumWithEntity(SymbolInterface $symbol): SymbolInterface
    {
        return $symbol instanceof Symbol ? $symbol : $this->getOneByName($symbol->name());
    }
}
