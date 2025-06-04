<?php

namespace App\Command\Mixin;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Trading\Application\Symbol\Exception\SymbolNotFoundException;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function str_contains;

trait SymbolAwareCommand
{
    use ConsoleInputAwareCommand;

    private const string DEFAULT_SYMBOL_OPTION_NAME = 'symbol';
    private const string DEFAULT_SYMBOL_NAME = SymbolEnum::BTCUSDT->value;

    private ?string $symbolOptionName = null;

    private SymbolProvider $symbolProvider;

    public function withSymbolProvider(SymbolProvider $symbolProvider): void
    {
        $this->symbolProvider = $symbolProvider;
    }

    protected function symbolIsSpecified(): bool
    {
        return (bool)$this->paramFetcher->getStringOption($this->symbolOptionName, false);
    }

    /**
     * @throws SymbolNotFoundException
     */
    protected function getSymbol(): SymbolInterface
    {
        if ($this->symbolOptionName) {
            $symbolName = strtoupper($this->paramFetcher->getStringOption($this->symbolOptionName));
        } else {
            $symbolName = self::DEFAULT_SYMBOL_NAME;
        }

        return $this->symbolProvider->getOneByName($symbolName);
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
                return $this->positionService->getOpenedPositionsSymbols(...$exceptWhenGetAll);
            } elseif (str_contains($providedSymbolValue, ',')) {
                return $this->parseProvidedSymbols($providedSymbolValue);
            }

            throw $e;
        }

        return [$symbol];
    }

    /**
     * @return SymbolInterface[]
     */
    protected function parseProvidedSymbols(string $providedStringArray): array
    {
        $rawItems = explode(',', $providedStringArray);
        $symbols = [];
        foreach ($rawItems as $rawItem) {
            $symbols[] = $this->symbolProvider->getOneByName(strtoupper($rawItem));
        }
        return $symbols;
    }

    protected function configureSymbolArgs(
        string $symbolOptionName = self::DEFAULT_SYMBOL_OPTION_NAME,
        ?string $defaultValue = self::DEFAULT_SYMBOL_NAME,
    ): static {
        $this->symbolOptionName = $symbolOptionName;

        return $this->addOption($symbolOptionName, null, InputOption::VALUE_REQUIRED, 'Symbol', $defaultValue);
    }

    protected function isSymbolArgsConfigured(): bool
    {
        return $this->symbolOptionName !== null;
    }
}
