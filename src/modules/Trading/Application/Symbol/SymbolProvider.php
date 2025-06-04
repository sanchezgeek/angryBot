<?php

declare(strict_types=1);

namespace App\Trading\Application\Symbol;

use App\Trading\Application\Symbol\Exception\SymbolNotFoundException;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use App\Trading\Domain\Symbol\SymbolInterface;

readonly class SymbolProvider
{
    public function __construct(private SymbolRepository $symbolRepository)
    {
    }

    /**
     * @throws SymbolNotFoundException
     */
    public function getOneByName(string $name): Symbol
    {
        if (!$symbol = $this->symbolRepository->findOneByName($name)) {
            throw new SymbolNotFoundException(sprintf('Cannot find symbol by "%s" name', $name));
        }

        return $symbol;
    }

    /**
     * @throws SymbolNotFoundException
     */
    public function replaceEnumWithEntity(SymbolInterface $symbol): SymbolInterface
    {
        return $symbol instanceof Symbol ? $symbol : $this->getOneByName($symbol->name());
    }
}
