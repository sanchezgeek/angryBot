<?php

declare(strict_types=1);

namespace App\Trading\Application\Symbol;

use App\Domain\Coin\Coin;
use App\Trading\Application\Symbol\Exception\SymbolEntityNotFoundException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\QuoteCoinNotEqualsSpecifiedOneException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\UnsupportedAssetCategoryException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolsEntry;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolsHandler;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use App\Trading\Domain\Symbol\SymbolInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class SymbolProvider
{
    public function __construct(
        private SymbolRepository $symbolRepository,
        private InitializeSymbolsHandler $initializeSymbolsHandler, /** @todo | symbol | messageBus */
    ) {
    }

    /**
     * @throws SymbolEntityNotFoundException
     */
    public function getOneByName(string $name): Symbol
    {
        if ($symbol = $this->symbolRepository->findOneByName($name)) {
            return $symbol;
        }

        throw new SymbolEntityNotFoundException(sprintf('Cannot find symbol by "%s" name', $name));
    }

    /**
     * Can be safely used when there is no stored entity yet
     *
     * @throws UnsupportedAssetCategoryException
     * @throws QuoteCoinNotEqualsSpecifiedOneException
     */
    public function getOrInitialize(string $name, ?Coin $coin = null): Symbol
    {
        try {
            return $this->getOneByName($name);
        } catch (SymbolEntityNotFoundException $e) {
            try {
                return $this->initializeSymbolsHandler->handle(
                    new InitializeSymbolsEntry($name, $coin)
                );
            } catch (UniqueConstraintViolationException $e) {
                return $this->getOneByName($name);
            }
        }
    }

    /**
     * Use when need populate entities associations with Symbol
     *
     * @throws UnsupportedAssetCategoryException
     * @throws QuoteCoinNotEqualsSpecifiedOneException
     */
    public function replaceWithActualEntity(SymbolInterface $symbol): SymbolInterface
    {
        return $symbol instanceof Symbol ? $symbol : $this->getOrInitialize($symbol->name());
    }
}
