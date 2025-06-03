<?php

namespace App\Command\Mixin;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function str_contains;

trait SymbolAwareCommand
{
    use ConsoleInputAwareCommand;

    private const DEFAULT_SYMBOL_OPTION_NAME = 'symbol';
    private const DEFAULT_SYMBOL = SymbolEnum::BTCUSDT;

    private ?string $symbolOptionName = null;

    protected function symbolIsSpecified(): bool
    {
        return (bool)$this->paramFetcher->getStringOption($this->symbolOptionName, false);
    }

    protected function getSymbol(): SymbolInterface
    {
        if ($this->symbolOptionName) {
            $symbol = SymbolEnum::fromShortName($this->paramFetcher->getStringOption($this->symbolOptionName));
        } else {
            $symbol = self::DEFAULT_SYMBOL;
        }

        return $symbol;
    }

    /**
     * @return SymbolInterface[]
     * @throws Throwable
     */
    private function getSymbols(array $exceptWhenGetAll = []): array
    {
        try {
            $symbol = $this->getSymbol();
        } catch (Throwable $e) {
            $providedSymbolValue = $this->paramFetcher->getStringOption($this->symbolOptionName);
            if ($providedSymbolValue === 'all') {
                return $this->positionService->getOpenedPositionsSymbols($exceptWhenGetAll);
            } elseif (str_contains($providedSymbolValue, ',')) {
                return self::parseProvidedSymbols($providedSymbolValue);
            }
            throw $e;
        }

        return [$symbol];
    }

    /**
     * @return SymbolInterface[]
     */
    protected static function parseProvidedSymbols(string $providedStringArray): array
    {
        $rawItems = explode(',', $providedStringArray);
        $symbols = [];
        foreach ($rawItems as $rawItem) {
            $symbols[] = SymbolEnum::fromShortName($rawItem);
        }
        return $symbols;
    }

    protected function configureSymbolArgs(
        string $symbolOptionName = self::DEFAULT_SYMBOL_OPTION_NAME,
        ?SymbolInterface $defaultValue = self::DEFAULT_SYMBOL,
    ): static
    {
        $this->symbolOptionName = $symbolOptionName;

        return $this->addOption($symbolOptionName, null, InputOption::VALUE_REQUIRED, 'Symbol', $defaultValue?->value);
    }

    protected function isSymbolArgsConfigured(): bool
    {
        return $this->symbolOptionName !== null;
    }
}
