<?php

declare(strict_types=1);

namespace App\Trading\Application\Symbol;

use App\Trading\Application\Symbol\Exception\SymbolEntityNotFoundException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolsEntry;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolsHandler;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trading\Infrastructure\Cache\SymbolsCache;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class SymbolProvider
{
    private const int CACHE_TTL = 86400;

    private array $hotCache = [];

    public function __construct(
        private readonly SymbolRepository $symbolRepository,
        /** @todo messageBus */
        private readonly InitializeSymbolsHandler $initializeSymbolsHandler,
        private readonly EntityManagerInterface $entityManager,
        private readonly SymbolsCache $cache
    ) {
    }

    /**
     * @throws SymbolEntityNotFoundException
     */
    public function getOneByName(string $name): Symbol
    {
        if (isset($this->hotCache[$name])) {
            return $this->hotCache[$name];
        }

        return $this->hotCache[$name] = $this->cache->get(sprintf('symbols_%s', $name), function () use ($name) {
            if ($symbol = $this->symbolRepository->findOneByName($name)) return $symbol;
            throw new SymbolEntityNotFoundException(sprintf('Cannot find symbol by "%s" name', $name));
        }, self::CACHE_TTL);
    }

    /**
     * Can be safely used when there is no stored entity yet
     *
     * @throws InitializeSymbolException
     * @throws SymbolEntityNotFoundException
     */
    public function getOrInitialize(string $name): Symbol
    {
        try {
            return $this->getOneByName($name);
        } catch (SymbolEntityNotFoundException $e) {
            try {
                return $this->initializeSymbolsHandler->handle(
                    new InitializeSymbolsEntry($name)
                );
            } catch (UniqueConstraintViolationException $e) {
                return $this->getOneByName($name);
            }
        }
    }

    /**
     * Use when need populate entities associations with Symbol
     *
     * @throws SymbolEntityNotFoundException
     * @throws InitializeSymbolException
     */
    public function replaceWithActualEntity(SymbolInterface $symbol): SymbolInterface
    {
        return
            !$symbol instanceof Symbol || !$this->entityManager->getUnitOfWork()->isInIdentityMap($symbol)
                ? $this->getOrInitialize($symbol->name())
                : $symbol
        ;
    }
}
