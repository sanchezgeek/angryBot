<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\SyncPositions;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Trading\Application\Symbol\Exception\SymbolEntityNotFoundException;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\QuoteCoinNotEqualsSpecifiedOneException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\UnsupportedAssetCategoryException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckOpenedPositionsSymbolsHandler
{
    /**
     * @throws UnsupportedAssetCategoryException
     * @throws QuoteCoinNotEqualsSpecifiedOneException
     */
    public function __invoke(CheckOpenedPositionsSymbolsMessage $message): void
    {
        foreach ($this->positionService->getOpenedPositionsRawSymbols() as $symbolRaw) {
            $this->symbolProvider->getOrInitialize($symbolRaw);
        }

        // @todo | check minimum value and qnt: https://announcements.bybit.com/article/update-on-minimum-single-order-value-and-quantity-for-spot-and-margin-trading-pairs-blt96f1cea87db75db9/
    }

    public function __construct(
        private PositionServiceInterface $positionService,
        private SymbolProvider $symbolProvider,
    ) {
    }
}
