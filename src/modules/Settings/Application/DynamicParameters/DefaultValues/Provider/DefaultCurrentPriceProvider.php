<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\DefaultValues\Provider;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use InvalidArgumentException;

final readonly class DefaultCurrentPriceProvider implements ParameterDefaultValueProviderInterface
{
    public function __construct(
        private ExchangeServiceInterface $exchangeService
    ) {
    }

    public function getRequiredKeys(): array
    {
        return ['symbol'];
    }

    public function get(array $input): float
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('Symbol must be specified');
        }

        return $this->exchangeService->ticker(SymbolEnum::fromShortName(strtoupper($input['symbol'])))->indexPrice->value();
    }
}
