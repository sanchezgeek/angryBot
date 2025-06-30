<?php

declare(strict_types=1);

namespace App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket;

use App\Application\Notification\AppNotificationLoggerInterface;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Settings\PushStopSettings;
use App\Bot\Domain\Entity\Stop;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Stop\Application\Contract\Command\CreateStop;
use App\Stop\Application\Contract\CreateStopHandlerInterface;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Worker\AppContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CoverLossesAfterCloseByMarketConsumer
{
    public const int LIQUIDATION_DISTANCE_APPLICABLE_TO_NOT_MAKE_TRANSFER = 500;
    public const int PNL_PERCENT_TO_CLOSE_POSITIONS = 1000;
    public const PushStopSettings SETTING = PushStopSettings::Cover_Loss_After_Close_By_Market;

    public function __invoke(CoverLossesAfterCloseByMarketConsumerDto $dto): void
    {
        if ($this->settings->required(self::SETTING) !== true) {
            return;
        }

        $closedPosition = $dto->closedPosition;
        $symbol = $closedPosition->symbol;
        $side = $closedPosition->side;
        $loss = $dto->loss->value();

        if (
            $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol)) === false
            || $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol, $side)) === false
        ) {
            return;
        }

        $positions = $this->positionServiceWithoutCache->getAllPositions();
        $lastPrices = $this->positionServiceWithoutCache->getLastMarkPrices();

        $res = [];

        foreach ($positions as $symbolRaw => $symbolPositions) {
            if ($symbolRaw === $symbol->name()) {
                continue;
            }

            $first = $symbolPositions[array_key_first($symbolPositions)];
            $hedge = $first->getHedge();

            if ($hedge?->isEquivalentHedge()) {
                continue;
            }

            $main = $hedge?->mainPosition ?? $first;

            $last = $lastPrices[$symbolRaw];
            $mainPositionPnlPercent = $last->getPnlPercentFor($main);
            if ($mainPositionPnlPercent > self::PNL_PERCENT_TO_CLOSE_POSITIONS) {
                $res[] = $main;
            }
        }

        $count = count($res);
        $perPosition = $symbol->associatedCoinAmount($loss / 2 / $count)->value();
        $pct = 100 / $count;

        $commonMessage = sprintf('[ %s loss =( ] to cover %s (%.2f%% of %s)', $closedPosition->getCaption(), $perPosition, $pct, $loss);

        $context = [
            Stop::CLOSE_BY_MARKET_CONTEXT => true,
            Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true,
            Stop::CREATED_AFTER_OTHER_SYMBOL_LOSS => true,
        ];

        $covered = 0;
        foreach ($res as $candidate) {
            $candidateSymbol = $candidate->symbol;
            $lastPrice = $lastPrices[$candidateSymbol->name()];
            $candidateTicker = $this->exchangeService->ticker($candidateSymbol);

            $stopVolume = PnlHelper::getVolumeForGetWishedProfit($perPosition, $candidate->entryPrice()->deltaWith($lastPrice));
            $stopVolume = $candidateSymbol->roundVolumeDown($stopVolume);
            $stopPct = ($stopVolume / $candidate->size) * 100;
            $this->appNotifications->notify(
                sprintf('%s => close %s of %s [%.2f%% of whole position size]', $commonMessage, $stopVolume, $candidate->getCaption(), $stopPct)
            );

            $stopLength = $this->tradingParameters->regularPredefinedStopLength($candidateSymbol, PredefinedStopLengthSelector::Short);
            $distance = $stopLength->of($candidateTicker->indexPrice->value());
            $supplyStopPrice = $candidateTicker->indexPrice->modifyByDirection($candidate->side, PriceMovementDirection::TO_LOSS, $distance);

            ($this->createStopHandler)(
                new CreateStop(
                    symbol: $candidateSymbol,
                    positionSide: $candidate->side,
                    volume: $stopVolume,
                    price: $supplyStopPrice->value(),
                    context: $context
                )
            );

            $covered += $perPosition;
        }

        $loss = $loss - $covered;

        if (!AppContext::hasPermissionsToFundBalance()) {
            return;
        }

        $closedPosition = $this->positionService->getPosition($symbol, $closedPosition->side); # refresh

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

        $coin = $symbol->associatedCoin();
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
        private ExchangeServiceInterface $exchangeService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
        private PositionServiceInterface $positionService,
        private ByBitLinearPositionService $positionServiceWithoutCache,
        private AppSettingsProviderInterface $settings,
        private AppNotificationLoggerInterface $appNotifications,
        private CreateStopHandlerInterface $createStopHandler,
        private TradingParametersProviderInterface $tradingParameters
    ) {
    }
}
