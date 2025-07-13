<?php

declare(strict_types=1);

namespace App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Settings\PushStopSettings;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Stop\Application\Contract\Command\CreateStop;
use App\Stop\Application\Contract\CreateStopHandlerInterface;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Worker\AppContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Application\Messenger\Trading\CoverLossesAfterCloseByMarketConsumer\SkipTransferTest
 * @see \App\Tests\Functional\Application\Messenger\Trading\CoverLossesAfterCloseByMarketConsumer\SuccessTransferTest
 */
#[AsMessageHandler]
readonly class CoverLossesAfterCloseByMarketConsumer
{
    public const int THRESHOLD = 4;

    public const int LIQUIDATION_DISTANCE_APPLICABLE_TO_NOT_MAKE_TRANSFER = 500;
    public const int PNL_PERCENT_TO_CLOSE_POSITIONS = 1000;
    public const PushStopSettings SETTING = PushStopSettings::Cover_Loss_After_Close_By_Market;
    public const float WHOLE_LOSS_MULTIPLIER = 0.7;

    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
        private PositionServiceInterface $positionService,
        private ByBitLinearPositionService $positionServiceWithoutCache,
        private AppSettingsProviderInterface $settings,
        private AppNotificationsServiceInterface $appNotifications,
        private CreateStopHandlerInterface $createStopHandler,
        private TradingParametersProviderInterface $tradingParameters
    ) {
    }

    public function __invoke(CoverLossesAfterCloseByMarketConsumerDto $dto): void
    {
        if ($this->settings->required(self::SETTING) !== true) {
            return;
        }

        $closedPosition = $dto->closedPosition;
        $symbol = $closedPosition->symbol;
        $side = $closedPosition->side;
        $loss = $dto->loss->value();

        if ($loss < self::THRESHOLD) {
            return;
        }

        if (
            $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol)) === false
            || $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol, $side)) === false
        ) {
            return;
        }

        $covered = $this->processOtherPositions($symbol, $loss, $closedPosition);

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

    private function processOtherPositions(SymbolInterface $symbol, float $loss, Position $closedPosition): float
    {
        $covered = 0;

        $positions = $this->positionServiceWithoutCache->getAllPositions();
        $lastPrices = $this->positionServiceWithoutCache->getLastMarkPrices();

        $candidates = [];

        // @todo | cover-losses | найти самые профитные, не закрывать раньше времени (или не надо?) (максимальный-минимальный профит...)
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
            // @todo | cover-losses | use ta
            if ($mainPositionPnlPercent > self::PNL_PERCENT_TO_CLOSE_POSITIONS) {
                $candidates[$symbolRaw] = $main;
            }
        }

        if (!$candidates) {
            return $covered;
        }

        $map = [];
        foreach ($candidates as $candidate) {
            $symbolRaw = $candidate->symbol->name();
            $last = $lastPrices[$symbolRaw];
            $pnlPercent = $last->getPnlPercentFor($candidate);

            $map[$symbolRaw] = $pnlPercent;
        }

        asort($map);
        $map = array_reverse($map, true);
        $sort = array_keys($map);
        $arr = [];
        foreach ($sort as $symbolRaw) {
            $arr[$symbolRaw] = true;
        }

        $candidates = array_replace($arr, $candidates);

        $lossToCoverByOtherSymbols = $loss * self::WHOLE_LOSS_MULTIPLIER;
        $count = count($candidates);
        $pct = 100 / $count;

        $context = [
            Stop::CLOSE_BY_MARKET_CONTEXT => true,
            Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true,
            Stop::CREATED_AFTER_OTHER_SYMBOL_LOSS => true,
        ];

        while ($candidate = array_shift($candidates)) {
            $leftToCover = $lossToCoverByOtherSymbols - $covered;
            $candidatesLeft = count($candidates) + 1;

            $candidateSymbol = $candidate->symbol;
            $lastPrice = $lastPrices[$candidateSymbol->name()];
            $candidateTicker = $this->exchangeService->ticker($candidateSymbol);

            $stopLength = $this->tradingParameters->regularPredefinedStopLength($candidateSymbol, PredefinedStopLengthSelector::ModerateShort);
            $distance = $stopLength->of($candidateTicker->indexPrice->value());
            $supplyStopPrice = $candidateTicker->indexPrice->modifyByDirection($candidate->side, PriceMovementDirection::TO_LOSS, $distance);

            $lossPerPosition = $leftToCover / $candidatesLeft;

            $stopVolume = PnlHelper::getVolumeForGetWishedProfit($lossPerPosition, $candidate->entryPrice()->deltaWith($lastPrice));
            $stopVolume = ExchangeOrder::roundedToMin($candidateSymbol, $stopVolume, $supplyStopPrice)->getVolume();
            $stopPct = ($stopVolume / $candidate->size) * 100;
            $this->appNotifications->notify(
                sprintf(
                    '[%s loss] close %s of %s [%.2f%% of whole position size] to cover %s (%.2f%% of %s)',
                    $closedPosition->getCaption(),
                    $stopVolume,
                    $candidate->getCaption(),
                    $stopPct,
                    $symbol->associatedCoinAmount($lossPerPosition)->value(),
                    $pct,
                    $lossToCoverByOtherSymbols
                )
            );

            $this->createStopHandler->__invoke(
                new CreateStop(
                    symbol: $candidateSymbol,
                    positionSide: $candidate->side,
                    volume: $stopVolume,
                    price: $supplyStopPrice->value(),
                    context: $context
                )
            );

            $covered += $lossPerPosition;
        }

        return $covered;
    }
}
