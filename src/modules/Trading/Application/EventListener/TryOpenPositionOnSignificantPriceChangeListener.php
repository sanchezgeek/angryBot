<?php

declare(strict_types=1);

namespace App\Trading\Application\EventListener;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\ByBit\Service\Market\ByBitLinearMarketService;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Screener\Application\Event\SignificantPriceChangeFoundEvent;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionEntryDto;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionHandler;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;

#[AsEventListener]
final readonly class TryOpenPositionOnSignificantPriceChangeListener
{
    private const float PERCENT_OF_DEPOSIT_TO_RISK_OPTION = 0.5;

    public function __invoke(SignificantPriceChangeFoundEvent $event): void
    {
        if (!$event->tryOpenPosition) {
            return;
        }
        $info = $event->info->info;

        $priceMovement = $info->getPriceMovement();
        $positionSide = $priceMovement->isLossFor(Side::Sell) ? Side::Sell : Side::Buy;
        $symbol = $info->symbol;

        if ($openedPosition = $this->positionService->getPosition($symbol, $positionSide)) {
            self::output(sprintf('position on %s already opened', $openedPosition->getCaption()));
            return;
        }

        $priceToRelate = $this->exchangeService->ticker($symbol)->markPrice;
        $tradingStyle = $this->parameters->tradingStyle($symbol, $positionSide);
        $stopsGridsDefinition = $this->stopsGridDefinitionFinder->create($symbol, $positionSide, $priceToRelate, $tradingStyle);

        $inputDto = new OpenPositionEntryDto(
            symbol: $symbol,
            positionSide: $positionSide,
            percentOfDepositToRisk: new Percent(self::PERCENT_OF_DEPOSIT_TO_RISK_OPTION),
            withStops: true, // withStops: !$this->paramFetcher->getBoolOption(self::WITHOUT_STOPS_OPTION),
            closeAndReopenCurrentPosition: false,
            removeExistedStops: false,
            dryRun: false,
            outputEnabled: true,
            buyGridsDefinition: null,
            stopsGridsDefinition: $stopsGridsDefinition,
        );

        $leverageAlreadyChanged = false;
        $try = true;
        while ($try) {
            try {
                $this->openPositionHandler->handle($inputDto);
                $this->notifyAboutOpen($event, $inputDto);
            } catch (CannotAffordOrderCostException $e) {
                self::output(sprintf('Got "%s" => trying to increase leverage', $e->getMessage()));
                if ($leverageAlreadyChanged) {
                    $try = false;
                } else {
                    $maxLeverage = $this->marketService->getInstrumentInfo($symbol->name())->maxLeverage;

                    try {
                        $this->positionService->setLeverage($symbol, $maxLeverage, $maxLeverage);
                    } catch (Throwable) {}

                    $leverageAlreadyChanged = true;
                }
            } catch (Throwable $e) {
                self::output($e->getMessage());

                $try = false;
            }
        }
    }

    private function notifyAboutOpen(SignificantPriceChangeFoundEvent $event, OpenPositionEntryDto $inputDto): void
    {
        $info = $event->info;
        $symbol = $info->info->symbol;
        $priceChangePercent = $info->info->getPriceChangePercent()->setOutputFloatPrecision(2);

        $message = sprintf(
            '%s: open %s %s position [percentOfDepositToRisk = %s, stopsGrid = "%s"] (days=%.2f [! %s !] %s [days=%.2f from %s].price=%s vs curr.price = %s: Δ = %s (%s > %s) %s)',
            OutputHelper::shortClassName(self::class),
            $symbol->name(),
            $inputDto->positionSide->title(),
            $inputDto->percentOfDepositToRisk->setOutputFloatPrecision(2),
            $inputDto->stopsGridsDefinition,
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
        private ByBitLinearPositionService $positionService,
        private TradingParametersProviderInterface $parameters,
        private ExchangeServiceInterface $exchangeService,
        private OpenPositionHandler $openPositionHandler,
        private ByBitLinearMarketService $marketService,
        private OpenPositionStopsGridsDefinitions $stopsGridDefinitionFinder,
        private AppNotificationsServiceInterface $notifications,
    ) {
    }
}
