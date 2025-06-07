<?php

namespace App\Command\Mixin;

use App\Domain\Coin\Coin;
use Symfony\Component\Console\Input\InputOption;

trait CoinAwareCommand
{
    use ConsoleInputAwareCommand;

    private const string COIN_OPTION_NAME = 'coin';
    private const string DEFAULT_COIN = Coin::USDT->value;

    private ?string $coinOptionName = null;

    protected function getCoin(): Coin
    {
        return Coin::from($this->paramFetcher->getStringOption($this->coinOptionName));
    }

    protected function configureCoinArgs(
        string $coinOptionName = self::COIN_OPTION_NAME,
        ?string $defaultValue = self::DEFAULT_COIN,
    ): static {
        $this->coinOptionName = $coinOptionName;

        return $this->addOption($coinOptionName, null, InputOption::VALUE_REQUIRED, 'Coin', $defaultValue);
    }

    protected function isCoinArgsConfigured(): bool
    {
        return $this->coinOptionName !== null;
    }
}
