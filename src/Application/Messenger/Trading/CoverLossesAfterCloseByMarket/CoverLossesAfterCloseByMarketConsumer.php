<?php

declare(strict_types=1);

namespace App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Settings\PushStopSettings;
use App\Settings\Application\Service\AppSettingsProvider;
use App\Worker\AppContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CoverLossesAfterCloseByMarketConsumer
{
    public const LIQUIDATION_DISTANCE_APPLICABLE_TO_NOT_MAKE_TRANSFER = 500;

    public function __invoke(CoverLossesAfterCloseByMarketConsumerDto $dto): void
    {
        if (
            $this->settingsProvider->get(PushStopSettings::Cover_Loss_After_Close_By_Market) !== true
            || !AppContext::hasPermissionsToFundBalance()
        ) {
            return;
        }

        $loss = $dto->loss->value();
        $closedPosition = $dto->closedPosition;

        $closedPosition = $this->positionService->getPosition($closedPosition->symbol, $closedPosition->side); # refresh

        if (!$closedPosition) {
            return;
        }

        /**
         * Don't make transfer if it's about support losses. In this case transfer will be done on demand.
         * @see PushBuyOrdersHandler::canUseSpot (...$isSupportPositionForceBuyAfterSl...)
         */
        if ($closedPosition->isSupportPosition()) {
            return;
        }

        $coin = $closedPosition->symbol->associatedCoin();
        $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($coin);
        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($coin);

        $availableSpotBalance = $spotBalance->available();
        if ($availableSpotBalance < $loss) {
            return;
        }
        $freeContractBalance = $contractBalance->free();

        /** Skip if available SPOT balance is insufficient for fulfill CONTRACT (=> transfer have no sense) */
        if ($freeContractBalance < 0 && $availableSpotBalance < -$freeContractBalance) {
            # but only if position liquidation not in "warning" range
            if ($closedPosition->liquidationDistance() >= self::LIQUIDATION_DISTANCE_APPLICABLE_TO_NOT_MAKE_TRANSFER) {
                return;
            }
        }

        $this->exchangeAccountService->interTransferFromSpotToContract($coin, $loss);
    }

    public function __construct(
        private ExchangeAccountServiceInterface $exchangeAccountService,
        private PositionServiceInterface $positionService,
        private AppSettingsProvider $settingsProvider,
    ) {
    }
}
