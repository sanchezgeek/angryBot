<?php

declare(strict_types=1);

namespace App\Application\Messenger;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Domain\Price\Price;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function abs;

#[AsMessageHandler]
final readonly class CheckPositionIsUnderLiquidationHandler
{
    public const WARNING_LIQUIDATION_DELTA = 90;
    public const CRITICAL_LIQUIDATION_DELTA = 40;

    public const DEFAULT_COIN_TRANSFER_AMOUNT = 15;
    public const STOP_DELTA = 40;
    public const STOP_TRIGGER_DELTA = 40;

    /**
     * @param ByBitLinearExchangeCacheDecoratedService $exchangeService
     */
    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private PositionServiceInterface $positionService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
        private OrderServiceInterface $orderService,
        private StopServiceInterface $stopService,
    ) {
    }

    public function __invoke(CheckPositionIsUnderLiquidation $message): void
    {
        $symbol = $message->symbol;
        $side = $message->side;

        $position = $this->positionService->getPosition($symbol, $side);
        $ticker = $this->exchangeService->ticker($symbol);

        if (!$position) {
            return;
        }

        $liquidationPrice = $position->liquidationPrice;
        $delta = abs($liquidationPrice - $ticker->markPrice);

        if (
            $delta <= self::WARNING_LIQUIDATION_DELTA
            && ($spotBalance = $this->exchangeAccountService->getSpotWalletBalance($coin = $symbol->associatedCoin()))
            && $spotBalance->availableBalance > 0
        ) {
            $amount = min(self::DEFAULT_COIN_TRANSFER_AMOUNT, $spotBalance->availableBalance);

//            $liqPrice = Price::float($liquidationPrice);
//            $price = $position->isShort() ? $liqPrice->sub(self::STOP_DELTA) : $liqPrice->add(self::STOP_DELTA);
//            $this->stopService->create($position->side, $price->value(), Percent::string('10%')->of($position->size), self::STOP_TRIGGER_DELTA);
//
            $this->exchangeAccountService->interTransferFromSpotToContract($coin, $amount);

            if ($delta <= self::CRITICAL_LIQUIDATION_DELTA) {
                $this->orderService->closeByMarket($position, Percent::string('10%')->of($position->size));
            }
        }
    }
}
