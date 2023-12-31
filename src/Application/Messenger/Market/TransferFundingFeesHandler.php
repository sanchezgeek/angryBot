<?php

declare(strict_types=1);

namespace App\Application\Messenger\Market;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Domain\Price\Helper\PriceHelper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

use function sleep;

#[AsMessageHandler]
final readonly class TransferFundingFeesHandler
{
    public function __construct(
        private MarketServiceInterface $marketService,
        private PositionServiceInterface $positionService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
    ) {
    }

    public function __invoke(TransferFundingFees $message): void
    {
        $symbol = $message->symbol;
        $positionSide = $message->side;

        $position = $this->positionService->getPosition($symbol, $positionSide);

        $prevPeriodRate = PriceHelper::round($this->marketService->getPreviousPeriodFundingRate($symbol), 7);
        var_dump(sprintf('prev period funding rate: %.7f', $prevPeriodRate));

        if (!$position) {
            return;
        }

        $sign = $position->isShort() ? 1 : -1;
        $fee = $sign * PriceHelper::round($position->value * $prevPeriodRate, 4);
        $coin = $symbol->associatedCoin();

        $tries = 0;
        $result = false;
        while (!$result && $tries < 5) {
            $tries++;
            try {
                if ($fee > 0) {
                    $this->exchangeAccountService->interTransferFromContractToSpot($coin, $fee);
                } elseif ($fee < 0) {
                    $this->exchangeAccountService->interTransferFromSpotToContract($coin, -$fee);
                }
                $result = true;
            } catch (Throwable $e) {
                var_dump($e->getMessage());
                sleep(1);
            }
        }
    }
}
