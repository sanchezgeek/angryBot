<?php

declare(strict_types=1);

namespace App\Application\Messenger\Market;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Clock\ClockInterface;
use App\Domain\Price\Helper\PriceHelper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final readonly class TransferFundingFeesHandler
{
    public function __construct(
        private ClockInterface $clock,
        private MarketServiceInterface $marketService,
        private PositionServiceInterface $positionService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
    ) {
    }

    public function __invoke(TransferFundingFees $message): void
    {
//        var_dump(sprintf('imhere. dispatched: %s, now: %s', $message->getDispatchedDateTime()->format('H:i:s'), $this->clock->now()->format('H:i:s')));
        $symbol = $message->symbol;
        $positionSide = $message->side;

        $position = $this->positionService->getPosition($symbol, $positionSide);

        if (!$position) {
            return;
        }

        $prevPeriodRate = PriceHelper::round($this->marketService->getPreviousPeriodFundingRate($symbol), 7);
        var_dump(sprintf('prev period funding rate: %.7f', $prevPeriodRate));

        $fee = PriceHelper::round($position->value * $prevPeriodRate, 4);
        $coin = $symbol->associatedCoin();
        $direction = $fee > 0 ? 'from contract to spot' : 'from spot to contract';

        if ($fee > 0) {
            $this->exchangeAccountService->interTransferFromContractToSpot($coin, $fee);
        } else {
            $this->exchangeAccountService->interTransferFromSpotToContract($coin, $fee);
        }
//        var_dump(sprintf('%.4f %s transferred %s (prev period funding rate: %.7f)', $fee, $coin->name, $direction, $prevPeriodRate));
    }
}
