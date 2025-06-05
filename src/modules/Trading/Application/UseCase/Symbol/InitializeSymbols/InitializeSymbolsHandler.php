<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\Symbol\InitializeSymbols;

use App\Application\Notification\AppNotificationLoggerInterface;
use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\Service\Market\ByBitLinearMarketService;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class InitializeSymbolsHandler
{
    private const array AvailableContractTypes = [
        'LinearPerpetual',
    ];

    /**
     * @throws UniqueConstraintViolationException
     * @throws InitializeSymbolException
     */
    public function handle(InitializeSymbolsEntry $entry): Symbol
    {
        $name = $entry->symbolName;

        $info = $this->marketService->getInstrumentInfo($name);

        $checkCoin = $entry->quoteCoin;
        if ($checkCoin && $info->quoteCoin !== $checkCoin->value) {
            throw new InitializeSymbolException(
                sprintf('QuoteCoin ("%s") !== specified coin ("%s")', $info->quoteCoin, $checkCoin->value)
            );
        }

        if (!in_array($info->contractType, self::AvailableContractTypes, true)) {
            throw new InitializeSymbolException(
                sprintf('Unknown category "%s". Available categories: "%s"', $info->contractType, implode('", "', self::AvailableContractTypes))
            );
        }
        $category = AssetCategory::linear;

        $symbol = new Symbol(
            $name,
            Coin::from($info->quoteCoin),
            $category,
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
