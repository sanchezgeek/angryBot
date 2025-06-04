<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\Symbol\InitializeSymbols;

use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use Exception;

final readonly class InitializeSymbolsHandler
{
    public function __construct(
        private ByBitLinearExchangeService $exchangeService,
        private SymbolRepository $symbolRepository
    ) {
    }

    public function handle(InitializeSymbolsEntry $entry): Symbol
    {
        $symbolName = $entry->symbolName;

        $info = $this->exchangeService->getInstrumentInfo($symbolName);

        $category = match ($info->contractType) {
            'LinearPerpetual' => AssetCategory::linear,
            default => throw new Exception(sprintf('Unknown %s asset categy (while parse %s)', $info->contractType, $symbolName))
        };


        $symbol = new Symbol(
            $symbolName,
            Coin::from($info->quoteCoin),
            $category,
            $info->minOrderQty,
            $info->minOrderValue,
            $info->priceScale
        );

        $this->symbolRepository->save($symbol);

        return $symbol;
    }
}
