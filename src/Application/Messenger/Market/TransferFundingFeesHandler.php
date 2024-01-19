<?php

declare(strict_types=1);

namespace App\Application\Messenger\Market;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
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
        $positions = []; /** @var Position[] $positions */
        if ($short = $this->positionService->getPosition($symbol, Side::Sell)) {
            $positions[] = $short;
            if ($short->oppositePosition !== null) {
                $positions[] = $short->oppositePosition;
            }
        } elseif ($long = $this->positionService->getPosition($symbol, Side::Buy)) {
            $positions[] = $long;
        }

        if (!$positions) {
            return;
        }

        $totalValue = 0;
        foreach ($positions as $position) {
            $totalValue += $position->isShort() ? $position->value : -$position->value;
        }

        $prevPeriodRate = PriceHelper::round($this->marketService->getPreviousPeriodFundingRate($symbol), 7);
        var_dump(sprintf('prev period funding rate: %.7f', $prevPeriodRate));

        $coin = $symbol->associatedCoin();
        $fee = PriceHelper::round($totalValue * $prevPeriodRate, $coin->coinCostPrecision());

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
