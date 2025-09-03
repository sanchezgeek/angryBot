<?php

declare(strict_types=1);

namespace App\Trading\Application\EventListener;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Domain\Position\Helper\InitialMarginHelper;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\TradingStyle;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\Market\ByBitLinearMarketService;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Screener\Application\Event\SignificantPriceChangeFoundEvent;
use App\Settings\Application\Helper\SettingsHelper;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\Settings\AutoOpenPositionSettings;
use App\Trading\Application\UseCase\OpenPosition\Exception\InsufficientAvailableBalanceException;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionEntryDto;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionHandler;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use App\Trading\Contract\ContractBalanceProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;

#[AsEventListener]
final readonly class TryOpenPositionOnSignificantPriceChangeListener
{
    public function __invoke(SignificantPriceChangeFoundEvent $event): void
    {
        // @todo | autoOpen | check funding
        if (!$event->tryOpenPosition) return;

        $priceChangeInfo = $event->info->info;
        $symbol = $priceChangeInfo->symbol;
        $positionSide = $event->positionSideToPositionLoss();

        $tradingStyle = $this->parameters->tradingStyle($symbol, $positionSide);
//        if ($tradingStyle === TradingStyle::Cautious) self::output(sprintf('skip autoOpen (cautious trading style for %s %s)', $symbol->name(), $positionSide->title()));

        if (!SettingsHelper::withAlternatives(AutoOpenPositionSettings::AutoOpen_Enabled, $symbol, $positionSide)) {
            self::output(sprintf('skip autoOpen (disabled for %s %s', $symbol->name(), $positionSide->title()));
            return;
        }

        if ($positionSide === Side::Buy) return; // skip (now only for SHORTs)

        $ticker = $this->exchangeService->ticker($symbol);
        $currentPricePartOfAth = TA::currentPricePartOfAth($symbol, $ticker->markPrice);

        [$threshold, $minPercentOfDepositToRisk, $maxPercentOfDepositToRisk] = match ($tradingStyle) {
            TradingStyle::Cautious => [70, 0.8, 5],
            default => [60, 1.5, 10],
            TradingStyle::Aggressive => [50, 2.5, 12],
        };

        // other logic for LONGs
        if ($currentPricePartOfAth->value() < $threshold) {
            self::output(
                sprintf('skip autoOpen on %s %s ($currentPricePartOfAth (%s) < %s)', $symbol->name(), $positionSide->title(), $currentPricePartOfAth, $threshold)
            );
            return;
        }

        $percentOfDepositToRisk = $currentPricePartOfAth->of($maxPercentOfDepositToRisk);
        $percentOfDepositToRisk = max($percentOfDepositToRisk, $minPercentOfDepositToRisk);
        $percentOfDepositToRisk = new Percent($percentOfDepositToRisk);

        $asBuyOrder = false;
        if ($openedPosition = $this->positionService->getPosition($symbol, $positionSide)) {
            $realInitialMargin = InitialMarginHelper::realInitialMargin($openedPosition);
            $available = OpenPositionHandler::balanceConsideredAsAvailableForTrade(
                $this->contractBalanceProvider->getContractWalletBalance($symbol->associatedCoin())
            );

            if ($available <= 0) {
                return;
            }

            // @todo | autoOpen | check buyOrders volume
            $balanceCanUseForOpen = $percentOfDepositToRisk->of($available);
            $positionImPercentOfAvailableForOpen = Percent::fromPart($realInitialMargin / $balanceCanUseForOpen, false);

            $commonInfo = sprintf('currentRealIm=%s | %s of %s available for open [%s]', $realInitialMargin, $positionImPercentOfAvailableForOpen->setOutputFloatPrecision(2), $percentOfDepositToRisk->setOutputFloatPrecision(2), $balanceCanUseForOpen);

            $canUseAdditional = $positionImPercentOfAvailableForOpen->getComplement(false);
            if ($canUseAdditional->value() <= 0) {
                self::output(sprintf('position on %s already opened (%s)', $openedPosition->getCaption(), $commonInfo));
                return;
            }

            $percentOfDepositToRisk = $canUseAdditional->of($percentOfDepositToRisk);
            $asBuyOrder = true;

            self::output(
                sprintf('add additional im (%s%% of availableBalance = %s) to %s (%s)', $percentOfDepositToRisk->setOutputFloatPrecision(2), $available, $openedPosition->getCaption(), $commonInfo)
            );
        }

        $priceToRelate = $ticker->markPrice;
        $fromPnlPercent = $tradingStyle === TradingStyle::Cautious ? PriceDistanceSelector::VeryVeryShort->toStringWithNegativeSign() : null;

        $stopsGridsDefinition = $this->stopsGridDefinitionFinder->create($symbol, $positionSide, $priceToRelate, $tradingStyle, $fromPnlPercent);

        // @todo | autoOpen | mb without stops in case of very small amount?
        $openPositionEntry = new OpenPositionEntryDto(
            symbol: $symbol,
            positionSide: $positionSide,
            percentOfDepositToRisk: $percentOfDepositToRisk,
            withStops: true, // withStops: !$this->paramFetcher->getBoolOption(self::WITHOUT_STOPS_OPTION),
            closeAndReopenCurrentPosition: false,
            removeExistedStops: false,
            dryRun: false,
            outputEnabled: true,
            buyGridsDefinition: null,
            stopsGridsDefinition: $stopsGridsDefinition,
            asBuyOrder: $asBuyOrder
        );

        try {
            $this->setMaxLeverage($symbol);
            $this->openPositionHandler->handle($openPositionEntry);
            $this->notifyAboutSuccess($event, $openPositionEntry);
        } catch (CannotAffordOrderCostException|InsufficientAvailableBalanceException $e) {
            $this->notifyAboutFail($openPositionEntry, $e, true);
        } catch (Throwable $e) {
            $this->notifyAboutFail($openPositionEntry, $e);
        }
    }

    private function setMaxLeverage(SymbolInterface $symbol): void
    {
        $maxLeverage = $this->marketService->getInstrumentInfo($symbol->name())->maxLeverage;

        try {
            $this->positionService->setLeverage($symbol, $maxLeverage, $maxLeverage);
        } catch (Throwable) {}
    }

    private function notifyAboutFail(OpenPositionEntryDto $openHandlerEntry, Throwable $e, bool $muted = false): void
    {
        $message = sprintf('%s: got "%s (%s)" error. Entry was: %s', OutputHelper::shortClassName($this), get_class($e), $e->getMessage(), $openHandlerEntry);

        if (!self::isNotificationsEnabled($openHandlerEntry->symbol, $openHandlerEntry->positionSide)) {
            $muted = true;
        }

        $muted ? $this->notifications->muted($message) : $this->notifications->error($message);
        self::output($message);
    }

    private function notifyAboutSuccess(SignificantPriceChangeFoundEvent $event, OpenPositionEntryDto $openHandlerEntry): void
    {
        $info = $event->info;
        $symbol = $info->info->symbol;
        $priceChangePercent = $info->info->getPriceChangePercent()->setOutputFloatPrecision(2);

        $message = sprintf(
            '%s: %s %s %s position [percentOfDepositToRisk = %s, stopsGrid = "%s"] (days=%.2f [! %s !] %s [days=%.2f from %s].price=%s vs curr.price = %s: Δ = %s (%s > %s) %s)',
            OutputHelper::shortClassName(self::class),
            $openHandlerEntry->asBuyOrder ? 'add additional to' : 'open',
            $symbol->name(),
            $openHandlerEntry->positionSide->title(),
            $openHandlerEntry->percentOfDepositToRisk->setOutputFloatPrecision(2),
            $openHandlerEntry->stopsGridsDefinition,
            $info->info->partOfDayPassed,
            $priceChangePercent,
            $symbol->name(),
            $info->info->partOfDayPassed,
            $info->info->fromDate->format('m-d'),
            $info->info->fromPrice,
            $info->info->toPrice,
            $info->info->priceDelta(),
            $priceChangePercent,
            $info->pricePercentChangeConsideredAsSignificant->setOutputFloatPrecision(2), // @todo | priceChange | +/-
            $symbol->name(),
        );

        $muted = !self::isNotificationsEnabled($openHandlerEntry->symbol, $openHandlerEntry->positionSide);

        $muted ? $this->notifications->muted($message) : $this->notifications->warning($message);
        self::output($message);
    }

    private static function isNotificationsEnabled(SymbolInterface $symbol, Side $positionSide): bool
    {
        return SettingsHelper::withAlternatives(AutoOpenPositionSettings::Notifications_Enabled, $symbol, $positionSide) === true;
    }

    private static function output(string $message): void
    {
        OutputHelper::warning(
            sprintf('%s: %s', OutputHelper::shortClassName(self::class), $message)
        );
    }

    public function __construct(
        private PositionServiceInterface $positionService,
        private TradingParametersProviderInterface $parameters,
        private ExchangeServiceInterface $exchangeService,
        private OpenPositionHandler $openPositionHandler,
        private ByBitLinearMarketService $marketService,
        private OpenPositionStopsGridsDefinitions $stopsGridDefinitionFinder,
        private AppNotificationsServiceInterface $notifications,
        private ContractBalanceProviderInterface $contractBalanceProvider,
    ) {
    }
}
