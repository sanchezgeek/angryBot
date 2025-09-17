<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Handler;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\Helper\InitialMarginHelper;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\Market\ByBitLinearMarketService;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Settings\Application\Helper\SettingsHelper;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Handler\Review\AutoOpenClaimReviewer;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\Settings\AutoOpenPositionSettings;
use App\Trading\Application\UseCase\OpenPosition\Exception\InsufficientAvailableBalanceException;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionEntryDto;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionHandler;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use App\Trading\Contract\ContractBalanceProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use Throwable;

final readonly class AutoOpenHandler
{
    public function handle(InitialPositionAutoOpenClaim $claim): void
    {
        $symbol = $claim->symbol;
        $positionSide = $claim->positionSide;

        $claimReview = $this->claimReviewer->handle($claim);

        if (!$claimReview->suggestedParameters) {
            self::output(sprintf('skip autoOpen on %s %s (%s)', $symbol->name(), $positionSide->title(), json_encode($claimReview->info(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
            return;
        }

        OutputHelper::print($claimReview);

        $percentOfDepositToUseAsMargin = $claimReview->suggestedParameters->percentOfDepositToUseAsMargin;

        // @todo | probably better to get from claim checker?
        $riskLevel = $this->parameters->riskLevel($symbol, $positionSide);

        $asBuyOrder = false;
        if ($openedPosition = $this->positionService->getPosition($symbol, $positionSide)) {
            $realInitialMargin = InitialMarginHelper::realInitialMargin($openedPosition);

            // либо которые force
            $orders = $this->buyOrderRepository->getCreatedAsBuyOrdersOnOpenPosition($symbol, $positionSide);
            foreach ($orders as $order) {
                $realInitialMargin += $this->orderCostCalculator->orderMargin(new ExchangeOrder($symbol, $order->getVolume(), $order->getPrice()), new Leverage(100))->value();
            }

            $available = OpenPositionHandler::balanceConsideredAsAvailableForTrade(
                $this->contractBalanceProvider->getContractWalletBalance($symbol->associatedCoin())
            );

            if ($available <= 0) {
                return;
            }

            $balanceCanUseForOpen = $percentOfDepositToUseAsMargin->of($available);
            $positionImPercentOfAvailableForOpen = Percent::fromPart($realInitialMargin / $balanceCanUseForOpen, false);

            $commonInfo = sprintf('currentRealIm=%s | %s of %s available for open [%s]', $realInitialMargin, $positionImPercentOfAvailableForOpen->setOutputFloatPrecision(2), $percentOfDepositToUseAsMargin->setOutputFloatPrecision(2), $balanceCanUseForOpen);

            $canUseAdditional = $positionImPercentOfAvailableForOpen->getComplement(false);
            if ($canUseAdditional->value() <= 0) {
                self::output(sprintf('position on %s already opened (%s)', $openedPosition->getCaption(), $commonInfo));
                return;
            }

            $percentOfDepositToUseAsMargin = $canUseAdditional->of($percentOfDepositToUseAsMargin);

            // mb remove other existed orders?
            $asBuyOrder = true;

            self::output(
                sprintf('add additional im (%s%% of availableBalance = %s) to %s (%s)', $percentOfDepositToUseAsMargin->setOutputFloatPrecision(2), $available, $openedPosition->getCaption(), $commonInfo)
            );
        }

        $ticker = $this->exchangeService->ticker($symbol);

        $priceToRelate = $ticker->markPrice;
        $fromPnlPercent = $riskLevel === RiskLevel::Cautious ? PriceDistanceSelector::VeryVeryShort->toStringWithNegativeSign() : null;

        // @todo | autoOpen | without stops? or other grid
        $stopsGridsDefinition = $this->stopsGridDefinitionFinder->create($symbol, $positionSide, $priceToRelate, $riskLevel, $fromPnlPercent);

        // @todo | autoOpen | mb without stops in case of very small amount?
        $openPositionEntry = new OpenPositionEntryDto(
            symbol: $symbol,
            positionSide: $positionSide,
            percentOfDepositToRisk: $percentOfDepositToUseAsMargin,
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
            // @todo throw event and handle in some listener
            $this->notifyAboutSuccess($claim, $openPositionEntry);
        } catch (CannotAffordOrderCostException|InsufficientAvailableBalanceException) {

        } catch (Throwable $e) {
            // @todo throw event and handle in some listener
            $this->notifyAboutFail($openPositionEntry, $e);
        }
    }

    private function notifyAboutFail(OpenPositionEntryDto $openHandlerEntry, Throwable $e, bool $muted = false): void
    {
        // @todo add claim reason?
        $message = sprintf('%s: got "%s (%s)" error. Entry was: %s', OutputHelper::shortClassName($this), get_class($e), $e->getMessage(), $openHandlerEntry);

        if (!self::isNotificationsEnabled($openHandlerEntry->symbol, $openHandlerEntry->positionSide)) {
            $muted = true;
        }

        $muted ? $this->notifications->muted($message) : $this->notifications->error($message);
        self::output($message);
    }

    private function notifyAboutSuccess(InitialPositionAutoOpenClaim $claim, OpenPositionEntryDto $openHandlerEntry): void
    {
        $reason = $claim->reason->getStringInfo();
        $symbol = $claim->symbol;

        $message = sprintf(
            '%s: %s %s %s position [percentOfDepositToRisk = %s, stopsGrid = "%s"] (reason: %s)',
            OutputHelper::shortClassName(self::class),
            $openHandlerEntry->asBuyOrder ? 'add additional to' : 'open',
            $symbol->name(),
            $openHandlerEntry->positionSide->title(),
            $openHandlerEntry->percentOfDepositToRisk->setOutputFloatPrecision(2),
            $openHandlerEntry->stopsGridsDefinition,
            $reason
        );

        $muted = !self::isNotificationsEnabled($openHandlerEntry->symbol, $openHandlerEntry->positionSide);

        $muted ? $this->notifications->muted($message) : $this->notifications->warning($message);
        self::output($message);
    }

    private static function isNotificationsEnabled(SymbolInterface $symbol, Side $positionSide): bool
    {
        return SettingsHelper::withAlternatives(AutoOpenPositionSettings::Notifications_Enabled, $symbol, $positionSide) === true;
    }

    private function setMaxLeverage(SymbolInterface $symbol): void
    {
        $maxLeverage = $this->marketService->getInstrumentInfo($symbol->name())->maxLeverage;

        try {
            $this->positionService->setLeverage($symbol, $maxLeverage, $maxLeverage);
        } catch (Throwable) {}
    }

    private static function output(string $message): void
    {
        OutputHelper::print('');
        OutputHelper::warning(
            sprintf('%s: %s', OutputHelper::shortClassName(self::class), $message)
        );
        OutputHelper::print('');
    }

    public function __construct(
        private AutoOpenClaimReviewer $claimReviewer,

        private PositionServiceInterface $positionService,
        private TradingParametersProviderInterface $parameters,
        private ExchangeServiceInterface $exchangeService,
        private OpenPositionHandler $openPositionHandler,
        private ByBitLinearMarketService $marketService,
        private OpenPositionStopsGridsDefinitions $stopsGridDefinitionFinder,
        private AppNotificationsServiceInterface $notifications,
        private ContractBalanceProviderInterface $contractBalanceProvider,
        private BuyOrderRepository $buyOrderRepository,
        private OrderCostCalculator $orderCostCalculator,
    ) {
    }

//    #[AppDynamicParameter(group: 'autoOpen', name: 'params')]
//    public function getDependencyInfo(): AbstractDependencyInfo
//    {
//        $info = [];
//        $info['cmd'] = ShowParametersCommand::url('autoOpen', 'params');
//
//        return InfoAboutEnumDependency::create(AutoOpenPositionParameters::class, RiskLevel::class, $info, 'autoOpen', 'params');
//    }
}
