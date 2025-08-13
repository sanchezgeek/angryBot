<?php

namespace App\Command\Mixin;

use App\Domain\Coin\Coin;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\QuoteCoinNotEqualsSpecifiedOneException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\UnsupportedAssetCategoryException;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\SymbolInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function str_contains;

trait SymbolAwareCommand
{
    use CoinAwareCommand;
    use ConsoleInputAwareCommand;

    private const string DEFAULT_SYMBOL_OPTION_NAME = 'symbol';
    private const string DEFAULT_EXCEPT_SYMBOL_OPTION_NAME = 'except';

    private const string DEFAULT_SYMBOL_NAME = 'BTCUSDT';

    private ?string $symbolOptionName = null;
    private ?string $symbolExceptOptionName = null;

    private SymbolProvider $symbolProvider;

    public function withSymbolProvider(SymbolProvider $symbolProvider): void
    {
        $this->symbolProvider = $symbolProvider;
    }

    protected function configureSymbolArgs(
        string $symbolOptionName = self::DEFAULT_SYMBOL_OPTION_NAME,
        ?string $defaultValue = self::DEFAULT_SYMBOL_NAME,
        string $symbolExceptOptionName = self::DEFAULT_EXCEPT_SYMBOL_OPTION_NAME,
    ): static {
        if (!$this->isCoinArgsConfigured()) {
            $this->configureCoinArgs();
        }

        $this->symbolOptionName = $symbolOptionName;
        $this->symbolExceptOptionName = $symbolExceptOptionName;

        return $this
            ->addOption($symbolOptionName, null, InputOption::VALUE_REQUIRED, 'Symbol', $defaultValue)
            ->addOption($symbolExceptOptionName, null, InputOption::VALUE_OPTIONAL, 'Symbol except')
        ;
    }

    protected function symbolIsSpecified(): bool
    {
        return (bool)$this->paramFetcher->getStringOption($this->symbolOptionName, false);
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
                $providedSymbols = $this->positionService->getOpenedPositionsSymbols(...$exceptWhenGetAll);
            } elseif (str_contains($providedSymbolValue, ',')) {
                $providedSymbols = $this->parseProvidedSymbols($providedSymbolValue);
            }

            if (isset($providedSymbols)) {
                if (
                    ($exceptSymbols = $this->paramFetcher->getStringOption($this->symbolExceptOptionName, false))
                    && $exceptSymbols = $this->parseProvidedSymbols($exceptSymbols)
                ) {
                    $providedSymbols = array_diff($providedSymbols, $exceptSymbols);
                }

                return $providedSymbols;
            }

            throw $e;
        }

        return [$symbol];
    }

    /**
     * @throws UnsupportedAssetCategoryException|QuoteCoinNotEqualsSpecifiedOneException
     */
    protected function getSymbol(): SymbolInterface
    {
        return $this->tryGetSymbolByProvidedName(
            $this->symbolOptionName ? $this->paramFetcher->getStringOption($this->symbolOptionName) : self::DEFAULT_SYMBOL_NAME
        );
    }

    /**
     * @return SymbolInterface[]
     * @throws UnsupportedAssetCategoryException|QuoteCoinNotEqualsSpecifiedOneException
     */
    protected function parseProvidedSymbols(string $providedStringArray): array
    {
        $rawItems = explode(',', $providedStringArray);

        $symbols = [];
        foreach ($rawItems as $rawItem) {
            $symbols[] = $this->tryGetSymbolByProvidedName($rawItem);
        }

        return $symbols;
    }

    protected function parseProvidedSingleSymbolAnswer(?string $symbolRaw): ?SymbolInterface
    {
        if ($symbolRaw === null) {
            return null;
        }

        $symbols = $this->parseProvidedSymbols($symbolRaw);
        if (count($symbols) > 1) {
            throw new InvalidArgumentException('Only for one symbol');
        }

        return $symbols[0];
    }

    /**
     * @throws UnsupportedAssetCategoryException|QuoteCoinNotEqualsSpecifiedOneException
     */
    private function tryGetSymbolByProvidedName(string $fullOrShortName): Symbol
    {
        $fullOrShortName = strtoupper($fullOrShortName);

        if ($fullOrShortName !== Coin::BTC->value) {
            $isCoinSpecified = false;
            foreach (Coin::cases() as $case) {
                if (str_contains($fullOrShortName, $case->value)) {
                    $isCoinSpecified = true;
                }
            }

            if (!$isCoinSpecified) {
                $fullOrShortName .= $this->getCoin()->value;
            }
        } else {
            $fullOrShortName .= $this->getCoin()->value;
        }

        return $this->symbolProvider->getOrInitialize($fullOrShortName);
    }

    protected function isSymbolArgsConfigured(): bool
    {
        return $this->symbolOptionName !== null;
    }
}
