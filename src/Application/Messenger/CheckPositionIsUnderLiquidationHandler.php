<?php

declare(strict_types=1);

namespace App\Application\Messenger;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function abs;
use function get_class;

#[AsMessageHandler]
final readonly class CheckPositionIsUnderLiquidationHandler
{
    public const DEFAULT_COIN_TRANSFER_AMOUNT = 15;

    /**
     * @param ByBitLinearExchangeCacheDecoratedService $exchangeService
     */
    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private PositionServiceInterface $positionService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
    ) {
    }

    public function __invoke(CheckPositionIsUnderLiquidation $message): void
    {
//        var_dump(
//            get_class($this->exchangeService),
//            get_class($this->positionService),
//            get_class($this->exchangeAccountService),
//        );

        $symbol = $message->symbol;
        $side = $message->side;

        $position = $this->positionService->getPosition($symbol, $side);
        $ticker = $this->exchangeService->ticker($symbol);

        if (!$position) {
            return;
        }

        if (
            abs($position->liquidationPrice - $ticker->markPrice) <= 70
            && ($spotBalance = $this->exchangeAccountService->getSpotWalletBalance($coin = $symbol->associatedCoin()))
            && $spotBalance->availableBalance > 0
        ) {
            $amount = min(self::DEFAULT_COIN_TRANSFER_AMOUNT, $spotBalance->availableBalance);

            $this->exchangeAccountService->interTransferFromSpotToContract($coin, $amount);
        }
    }
}
