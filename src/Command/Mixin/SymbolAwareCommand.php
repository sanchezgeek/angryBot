<?php

namespace App\Command\Mixin;

use App\Domain\Coin\Coin;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\QuoteCoinNotEqualsSpecifiedOneException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\UnsupportedAssetCategoryException;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function str_contains;

trait SymbolAwareCommand
{
    use CoinAwareCommand;
    use ConsoleInputAwareCommand;

    private const string DEFAULT_SYMBOL_OPTION_NAME = 'symbol';
    private const string DEFAULT_SYMBOL_NAME = 'BTCUSDT';

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

    protected function configureSymbolArgs(
        string $symbolOptionName = self::DEFAULT_SYMBOL_OPTION_NAME,
        ?string $defaultValue = self::DEFAULT_SYMBOL_NAME,
    ): static {
        if (!$this->isCoinArgsConfigured()) {
            $this->configureCoinArgs();
        }

        $this->symbolOptionName = $symbolOptionName;

        return $this->addOption($symbolOptionName, null, InputOption::VALUE_REQUIRED, 'Symbol', $defaultValue);
    }

    protected function isSymbolArgsConfigured(): bool
    {
        return $this->symbolOptionName !== null;
    }
}
