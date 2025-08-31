<?php

declare(strict_types=1);

namespace App\Trading\Application\EventListener;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\Market\ByBitLinearMarketService;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Screener\Application\Event\SignificantPriceChangeFoundEvent;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\UseCase\OpenPosition\Exception\InsufficientAvailableBalanceException;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionEntryDto;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionHandler;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;

#[AsEventListener]
final readonly class TryOpenPositionOnSignificantPriceChangeListener
{
    private const float MIN_PERCENT_OF_DEPOSIT_TO_RISK_OPTION = 1.5;
    private const float MAX_PERCENT_OF_DEPOSIT_TO_RISK_OPTION = 10;

    public function __invoke(SignificantPriceChangeFoundEvent $event): void
    {
        // @todo | autoOpen | check funding

        if (!$event->tryOpenPosition) {
            return;
        }

        $info = $event->info->info;
        $priceMovement = $info->getPriceMovement();
        $positionSide = $priceMovement->isLossFor(Side::Sell) ? Side::Sell : Side::Buy;
        $symbol = $info->symbol;

        if ($positionSide === Side::Buy) {
            self::output('skip (now only for SHORTs)');
            return;
        }

        if ($openedPosition = $this->positionService->getPosition($symbol, $positionSide)) {
            self::output(sprintf('position on %s already opened', $openedPosition->getCaption()));
            return;
        }

        $ticker = $this->exchangeService->ticker($symbol);

        $allTimeHighLow = TA::allTimeHighLow($symbol);
        $athDelta = $allTimeHighLow->delta();
        $currentPriceDeltaFromLow = $ticker->markPrice->deltaWith($allTimeHighLow->low);
        $currentPricePartOfAth = $currentPriceDeltaFromLow / $athDelta;
        $percentOfDepositToRisk = self::MAX_PERCENT_OF_DEPOSIT_TO_RISK_OPTION * $currentPricePartOfAth;
        $percentOfDepositToRisk = max($percentOfDepositToRisk, self::MIN_PERCENT_OF_DEPOSIT_TO_RISK_OPTION);

        $priceToRelate = $ticker->markPrice;
        $tradingStyle = $this->parameters->tradingStyle($symbol, $positionSide);
        $stopsGridsDefinition = $this->stopsGridDefinitionFinder->create($symbol, $positionSide, $priceToRelate, $tradingStyle);

        $openPositionEntry = new OpenPositionEntryDto(
            symbol: $symbol,
            positionSide: $positionSide,
            percentOfDepositToRisk: new Percent($percentOfDepositToRisk),
            withStops: true, // withStops: !$this->paramFetcher->getBoolOption(self::WITHOUT_STOPS_OPTION),
            closeAndReopenCurrentPosition: false,
            removeExistedStops: false,
            dryRun: false,
            outputEnabled: true,
            buyGridsDefinition: null,
            stopsGridsDefinition: $stopsGridsDefinition,
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

        $muted ? $this->notifications->muted($message) : $this->notifications->error($message);
        self::output($message);
    }

    private function notifyAboutSuccess(SignificantPriceChangeFoundEvent $event, OpenPositionEntryDto $openHandlerEntry): void
    {
        $info = $event->info;
        $symbol = $info->info->symbol;
        $priceChangePercent = $info->info->getPriceChangePercent()->setOutputFloatPrecision(2);

        $message = sprintf(
            '%s: open %s %s position [percentOfDepositToRisk = %s, stopsGrid = "%s"] (days=%.2f [! %s !] %s [days=%.2f from %s].price=%s vs curr.price = %s: Δ = %s (%s > %s) %s)',
            OutputHelper::shortClassName(self::class),
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

        $this->notifications->notify($message, [], 'warning');
    }

    private static function output(string $message): void
    {
        OutputHelper::print(
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
    ) {
    }
}
