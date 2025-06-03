<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use LogicException;

readonly final class TryReleaseActiveOrders
{
    public function __construct(
        public ?SymbolInterface $symbol = null,
        public ?float $forVolume = null,
        public bool $force = false
    ) {
    }

    public static function forStop(SymbolInterface $symbol, Stop $stop): self
    {
        return new self($symbol, $stop->getVolume());
    }

    public function isMessageForAllSymbols(): bool
    {
        return $this->symbol === null;
    }

    public function cloneForSymbol(SymbolInterface $symbol): self
    {
        if (!$this->isMessageForAllSymbols()) {
            throw new LogicException('Symbol already defined');
        }

        return new self(
            $symbol,
            $this->forVolume,
            $this->force
        );
    }
}
