<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\DefaultValues\Provider;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\DynamicParameters\DefaultValues\ParameterDefaultValueProviderInterface;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\Exception\DefaultPositionCannotBeProvided;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\UnsupportedAssetCategoryException;
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

    /**
     * @throws UnsupportedAssetCategoryException
     * @throws DefaultPositionCannotBeProvided
     */
    public function get(array $input): Position
    {
        if (!$input['symbol']) {
            throw new InvalidArgumentException('Symbol must be specified');
        }

        if (!$input['side']) {
            throw new InvalidArgumentException('Symbol must be specified');
        }

        $position = $this->positionService->getPosition($this->symbolProvider->getOrInitialize($input['symbol']), Side::from($input['side']));

        if (!$position) {
            throw new DefaultPositionCannotBeProvided();
        }

        return $position;
    }
}
