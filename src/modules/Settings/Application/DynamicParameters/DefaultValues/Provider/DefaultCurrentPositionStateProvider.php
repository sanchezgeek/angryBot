<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\DefaultValues\Provider;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\Trading\Application\Symbol\SymbolProvider;
use InvalidArgumentException;

final readonly class DefaultCurrentPositionStateProvider implements ParameterDefaultValueProviderInterface
{
    public function __construct(
        private PositionServiceInterface $positionService,
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function getRequiredKeys(): array
    {
        return [
            'symbol' => 'symbol',
            'side' => 'side',
        ];
    }

    public function get(array $input): Position
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('Symbol must be specified');
        }

        if (!$input['side']) {
            throw new InvalidArgumentException('Symbol must be specified');
        }

        return $this->positionService->getPosition($this->symbolProvider->getOrInitialize($input['symbol']), Side::from($input['side']));
    }
}
