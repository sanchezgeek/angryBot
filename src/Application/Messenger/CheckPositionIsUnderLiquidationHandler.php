<?php

declare(strict_types=1);

namespace App\Application\Messenger;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Repository\StopRepository;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function abs;

#[AsMessageHandler]
final readonly class CheckPositionIsUnderLiquidationHandler
{
    public const WARNING_LIQUIDATION_DELTA = 90;
    public const CRITICAL_LIQUIDATION_DELTA = 30;

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
//        private StopRepository $stopRepository,
    ) {
    }

    public function __invoke(CheckPositionIsUnderLiquidation $message): void
    {
        $symbol = $message->symbol;
        $positionSide = $message->side;

        $position = $this->positionService->getPosition($symbol, $positionSide);
        $ticker = $this->exchangeService->ticker($symbol);

        if (!$position) {
            return;
        }

        $priceDeltaToLiquidation = $position->priceDeltaToLiquidation($ticker);

        if ($priceDeltaToLiquidation <= self::CRITICAL_LIQUIDATION_DELTA) {
            $this->orderService->closeByMarket($position, Percent::string('10%')->of($position->size));
        }

        if (
            $priceDeltaToLiquidation <= self::WARNING_LIQUIDATION_DELTA
            && ($spotBalance = $this->exchangeAccountService->getSpotWalletBalance($coin = $symbol->associatedCoin()))
            && $spotBalance->availableBalance > 0
        ) {
            $amount = min(self::DEFAULT_COIN_TRANSFER_AMOUNT, $spotBalance->availableBalance);
            $this->exchangeAccountService->interTransferFromSpotToContract($coin, $amount);
        }

        return;

        /**
         * @todo
         * 1) проверить есть ли стопы в репозитории между ликвидацией и какой-то предельной допустимой ценой перед ликвидацией (из-за возможной разницы индекса и рын)
         *      - возможно в кач дельты нужно брать именно дельту в цене
         * 2) проверить стопы на бирже в том же диапаз
         * 3) если в итоге меньше опр-ного объёма, - нужно добавить. Желательно для хэндлера. В крайнем случае - по цене маркировки сразу на биржу
         *
         * переделать isPriceInRange?
         */

        $mPrice = 37600;
        $stops = $this->stopRepository->findActive(
            side: $positionSide,
            qbModifier: function (QueryBuilder $qb) use ($positionSide, $liquidationPrice, $mPrice) {
                $priceField = $qb->getRootAliases()[0] . '.price';

                $priceFrom = $liquidationPrice;
                $priceTo = $mPrice;

                if ($priceFrom > $priceTo) {
                    [$priceFrom, $priceTo] = [$priceTo, $priceFrom];
                }

                $qb
                    ->andWhere($priceField . ' BETWEEN :priceFrom AND :priceTo')
                    ->setParameter(':priceFrom', $priceFrom)
                    ->setParameter(':priceTo', $priceTo)
                ;
            }
        );

        $stops = new StopsCollection(...$stops);

        var_dump($stops->totalCount(), $stops->totalVolume());

//            $liqPrice = Price::float($liquidationPrice);
//            $price = $position->isShort() ? $liqPrice->sub(self::STOP_DELTA) : $liqPrice->add(self::STOP_DELTA);
//            $this->stopService->create($position->side, $price->value(), Percent::string('10%')->of($position->size), self::STOP_TRIGGER_DELTA);
    }
}
