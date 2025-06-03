<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\DefaultValues\Provider\LiquidationHandler;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use InvalidArgumentException;

final readonly class DefaultLiquidationHandlerHandledMessageProvider implements ParameterDefaultValueProviderInterface
{
    public function getRequiredKeys(): array
    {
        return ['symbol'];
    }

    public function get(array $input): CheckPositionIsUnderLiquidation
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('Symbol must be specified');
        }

        return new CheckPositionIsUnderLiquidation(SymbolEnum::fromShortName(strtoupper($input['symbol'])));
    }
}
