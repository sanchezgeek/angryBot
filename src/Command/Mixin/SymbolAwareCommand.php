<?php

namespace App\Command\Mixin;

use App\Bot\Domain\ValueObject\Symbol;
use App\Worker\AppContext;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

use Throwable;

use function sprintf;

trait SymbolAwareCommand
{
    use ConsoleInputAwareCommand;

    private const DEFAULT_SYMBOL_OPTION_NAME = 'symbol';
    private const DEFAULT_SYMBOL = Symbol::BTCUSDT;

    private ?string $symbolOptionName = null;

    protected function getSymbol(): Symbol
    {
        if ($this->symbolOptionName) {
            $providedSymbolValue = $this->paramFetcher->getStringOption($this->symbolOptionName);
            try {
                $symbol = $this->trySymbolFromValue($providedSymbolValue);
            } catch (InvalidArgumentException) {
                $symbol = $this->trySymbolFromValue($providedSymbolValue . 'USDT');
            }
        } else {
            $symbol = self::DEFAULT_SYMBOL;
        }

        return $symbol;
    }

    /**
     * @return Symbol[]
     * @throws Exception
     */
    private function getSymbols(): array
    {
        try {
            $symbol = $this->getSymbol();
        } catch (Exception $e) {
            $providedSymbolValue = $this->paramFetcher->getStringOption($this->symbolOptionName);
            if ($providedSymbolValue === 'all') {
                return AppContext::getOpenedPositions();
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