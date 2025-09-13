<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\DefaultValues\Provider;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\Trading\Application\Symbol\SymbolProvider;
use InvalidArgumentException;

final readonly class DefaultCurrentPriceProvider implements ParameterDefaultValueProviderInterface
{
    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function getRequiredKeys(): array
    {
        return [
            'symbol' => 'symbol',
        ];
    }

    public function get(array $input): float
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('Symbol must be specified');
        }

        return $this->exchangeService->ticker($this->symbolProvider->getOrInitialize($input['symbol']))->indexPrice->value();
    }
}
