<?php

namespace App\Command\Mixin;

use App\Bot\Domain\ValueObject\Symbol;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

use function sprintf;

trait SymbolAwareCommand
{
    use ConsoleInputAwareCommand;

    private const DEFAULT_SYMBOL_OPTION_NAME = 'symbol';
    private const DEFAULT_SYMBOL = Symbol::BTCUSDT;

    private ?string $symbolOptionName = null;

    private function getSymbol(): Symbol
    {
        if ($this->symbolOptionName) {
            $providedSymbolValue = $this->paramFetcher->getStringOption($this->symbolOptionName);
            if (!$symbol = Symbol::tryFrom($providedSymbolValue)) {
                throw new InvalidArgumentException(
                    sprintf('Invalid $%s provided ("%s" given)', $this->symbolOptionName, $providedSymbolValue),
                );
            }
        } else {
            $symbol = self::DEFAULT_SYMBOL;
        }

        return $symbol;
    }

    private function configureSymbolArgs(string $symbolOptionName = self::DEFAULT_SYMBOL_OPTION_NAME): static
    {
        $this->symbolOptionName = $symbolOptionName;

        return $this->addOption($symbolOptionName, null, InputOption::VALUE_REQUIRED, 'Symbol', self::DEFAULT_SYMBOL->value);
    }

    private function isSymbolArgsConfigured(): bool
    {
        return $this->symbolOptionName !== null;
    }
}