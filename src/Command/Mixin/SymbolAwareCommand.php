<?php

namespace App\Command\Mixin;

use App\Bot\Domain\ValueObject\Symbol;
use App\Worker\AppContext;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function sprintf;
use function str_contains;

trait SymbolAwareCommand
{
    use ConsoleInputAwareCommand;

    private const DEFAULT_SYMBOL_OPTION_NAME = 'symbol';
    private const DEFAULT_SYMBOL = Symbol::BTCUSDT;

    private ?string $symbolOptionName = null;

    protected function getSymbol(): Symbol
    {
        if ($this->symbolOptionName) {
            $symbol = Symbol::fromShortName($this->paramFetcher->getStringOption($this->symbolOptionName));
        } else {
            $symbol = self::DEFAULT_SYMBOL;
        }

        return $symbol;
    }

    /**
     * @return Symbol[]
     * @throws Throwable
     */
    private function getSymbols(): array
    {
        try {
            $symbol = $this->getSymbol();
        } catch (Throwable $e) {
            $providedSymbolValue = $this->paramFetcher->getStringOption($this->symbolOptionName);
            if ($providedSymbolValue === 'all') {
                return AppContext::getOpenedPositions();
            } elseif (str_contains($providedSymbolValue, ',')) {
                $rawItems = explode(',', $providedSymbolValue);
                $symbols = [];
                foreach ($rawItems as $rawItem) {
                    $symbols[] = Symbol::fromShortName($rawItem);
                }
                return $symbols;
            }
            throw $e;
        }

        return [$symbol];
    }

    private function trySymbolFromValue(string $symbolName): Symbol
    {
        if (!$symbol = Symbol::tryFrom($symbolName)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $%s provided ("%s" given)', $this->symbolOptionName, $symbolName),
            );
        }

        return $symbol;
    }

    protected function configureSymbolArgs(string $symbolOptionName = self::DEFAULT_SYMBOL_OPTION_NAME): static
    {
        $this->symbolOptionName = $symbolOptionName;

        return $this->addOption($symbolOptionName, null, InputOption::VALUE_REQUIRED, 'Symbol', self::DEFAULT_SYMBOL->value);
    }

    protected function isSymbolArgsConfigured(): bool
    {
        return $this->symbolOptionName !== null;
    }
}