<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\Symbol\InitializeSymbols;

use App\Application\Notification\AppNotificationLoggerInterface;
use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\Service\Market\ByBitLinearMarketService;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\UnsupportedAssetCategoryException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\QuoteCoinNotEqualsSpecifiedOneException;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class InitializeSymbolsHandler
{
    private const array AVAILABLE_CATEGORIES = [
        'LinearPerpetual' => AssetCategory::linear,
    ];

    /**
     * @throws UniqueConstraintViolationException
     * @throws UnsupportedAssetCategoryException
     * @throws QuoteCoinNotEqualsSpecifiedOneException
     */
    public function handle(InitializeSymbolsEntry $entry): Symbol
    {
        $name = $entry->symbolName;

        $info = $this->marketService->getInstrumentInfo($name);

        $checkCoin = $entry->quoteCoin;
        if ($checkCoin && $info->quoteCoin !== $checkCoin->value) {
            throw new QuoteCoinNotEqualsSpecifiedOneException($info->quoteCoin, $checkCoin);
        }

        if (!($associatedCategory = self::AVAILABLE_CATEGORIES[$info->contractType] ?? null)) {
            throw new UnsupportedAssetCategoryException($info->contractType, array_keys(self::AVAILABLE_CATEGORIES));
        }

        $symbol = new Symbol(
            $name,
            Coin::from($info->quoteCoin),
            $associatedCategory,
            $info->minOrderQty,
            $info->minOrderValue,
            $info->priceScale
        );

        $this->symbolRepository->save($symbol);
        $this->notifications->notify(sprintf('"%s" symbol initialized', $symbol->name()));

        return $symbol;
    }

    public function __construct(
        private ByBitLinearMarketService $marketService,
        private SymbolRepository $symbolRepository,
        private AppNotificationLoggerInterface $notifications,
    ) {
    }
}
