<?php

declare(strict_types=1);

namespace App\Buy\Application\Handler\Command;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Ticker;
use App\Buy\Application\Command\CreateStopsAfterBuy;
use App\Buy\Application\Service\BaseStopLength\BaseStopLengthProcessorInterface;
use App\Buy\Application\Service\StopPlacement\DefaultStopPlacementStrategyProcessor;
use App\Buy\Application\Service\StopPlacement\Exception\OtherStrategySuggestionException;
use App\Buy\Application\Service\StopPlacement\StopPlacementStrategyContext;
use App\Buy\Application\Service\StopPlacement\StopPlacementStrategyProcessorInterface;
use App\Buy\Application\StopPlacementStrategy;
use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Stop\Application\Contract\Command\CreateStop;
use App\Stop\Application\Contract\CreateStopHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\TechnicalAnalysisToolsFactoryInterface;
use App\Trait\DispatchCommandTrait;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * @see \App\Tests\Functional\Modules\Stop\Applicaiton\Handler\CreateOppositeStopsAfterBuyCommandHandlerTest
 */
#[AsMessageHandler]
final class CreateStopsAfterBuyCommandHandler
{
    use DispatchCommandTrait;

    public const CandleIntervalEnum CHOOSE_FINAL_STOP_STRATEGY_INTERVAL = CandleIntervalEnum::D1;
    public const int CHOOSE_FINAL_STOP_STRATEGY_INTERVALS_COUNT = 2; // @todo | !!! PredefinedStopLengthParser parameters

    /**
     * @return Stop[]
     * @throws Throwable
     */
    public function __invoke(CreateStopsAfterBuy $command): array
    {
        $buyOrder = $this->buyOrderRepository->find($command->buyOrderId);

        $symbol = $buyOrder->getSymbol();
        $side = $buyOrder->getPositionSide();
        $volume = $buyOrder->getVolume();

        $position = $this->positionService->getPosition($symbol, $side);
        $ticker = $this->exchangeService->ticker($symbol);

        $context = [];
        if ($position->isSupportPosition()) {
            $context[Stop::CLOSE_BY_MARKET_CONTEXT] = true;
        }

        if ($specifiedStopDistance = $buyOrder->getOppositeOrderDistance()) {
            $triggerPrice = $side->isShort() ? $buyOrder->getPrice() + $specifiedStopDistance : $buyOrder->getPrice() - $specifiedStopDistance;
            $stop = $this->dispatchCommand(
                new CreateStop(
                    symbol: $position->symbol,
                    positionSide: $side,
                    volume: $volume,
                    price: $triggerPrice,
                    triggerDelta: null,
                    context: $context
                )
            );

            return [$stop];
        }

        $defaultStopPriceLengthParser = $this->getBasePriceLengthParser($buyOrder);
        $defaultStopPriceLength = $defaultStopPriceLengthParser->process($buyOrder);

        // @todo | based on liquidation if position under hedge?
        $strategy = $this->getStopStrategy($position, $buyOrder, $ticker, $defaultStopPriceLength);

        $stopStrategy = $strategy['strategy'];
        $description = $strategy['description'];

        $stopPlacementStrategyContext = new StopPlacementStrategyContext($ticker, $position, $buyOrder, $defaultStopPriceLength);
        [$stopPlacementStrategyProcessor, $note] = $this->getFinalStopStrategyProcessor($stopStrategy, $stopPlacementStrategyContext);
        $stopCreateCommands = $stopPlacementStrategyProcessor->process($stopStrategy, $stopPlacementStrategyContext);

        $stops = [];
        foreach ($stopCreateCommands as $createStopCommand) {
            $stops[] = $this->dispatchCommand($createStopCommand->addContext($context));
        }

        return $stops;
    }

    /**
     * @return array{strategy: StopPlacementStrategy, description: string}
     */
    private function getStopStrategy(Position $position, BuyOrder $buyOrder, Ticker $ticker, float $defaultStopPriceLength): array
    {
        if (($hedge = $position->getHedge()) && $hedge->isSupportPosition($position)) {
            $hedgeStrategy = $hedge->getHedgeStrategy();
            return [
                'strategy' => $hedgeStrategy->supportPositionStopCreation, // 'strategy' => $hedge->isSupportPosition($position) ? $hedgeStrategy->supportPositionStopCreation : $hedgeStrategy->mainPositionStopCreation,
                'description' => $hedgeStrategy->description,
            ];
        }

        $currentPrice = $ticker->indexPrice->value();
        $deltaWithTicker = $position->getDeltaWithTicker($ticker);

        $ta = $this->taProvider->create($buyOrder->getSymbol(), self::CHOOSE_FINAL_STOP_STRATEGY_INTERVAL);
        $averagePriceChange = $ta->averagePriceChangePrev(self::CHOOSE_FINAL_STOP_STRATEGY_INTERVALS_COUNT)->averagePriceChange;

        /**
         * (1) After first position existed stop
         *      reason: to increase position size (keep all stops volume on some initially selected level)
         */
        if ($deltaWithTicker >= $averagePriceChange->of($currentPrice)) { // 30000 => 4500 (4%) => m.b. averagePriceChange(1D)
            return [
                'strategy' => StopPlacementStrategy::AFTER_FIRST_STOP_UNDER_POSITION,
                'description' => sprintf('deltaWithTicker=%.2f > %s -> increase position size', $deltaWithTicker, $averagePriceChange),
            ];
        }

        $thirdPart = $averagePriceChange->divide(3);

        /**
         * (2) After position entry
         *  reasons:
         *      1) to reduce added by mistake on start (price move back and approve your mistake)
         *      2) keep position size on start (to not reduce position size by placing stop orders between position and ticker)
         */
        if ($deltaWithTicker >= $thirdPart->of($currentPrice)) { // 30000 => 1500 => m.b. averagePriceChange(1D) / 3
            return [
                'strategy' => StopPlacementStrategy::UNDER_POSITION,
                'description' => sprintf('deltaWithTicker=%.2f -> reduce volume added by mistake', $deltaWithTicker),
            ];
        }

        if ($deltaWithTicker >= $defaultStopPriceLength) {
            return [
                'strategy' => StopPlacementStrategy::UNDER_POSITION,
                'description' => sprintf('deltaWithTicker=%s > %s -> keep position size on start', $deltaWithTicker, $defaultStopPriceLength),
            ];
        }

        /** (3) Default (PredefinedStopLength / RiskToRewardStopLength) */
        return ['strategy' => StopPlacementStrategy::DEFAULT, 'description' => 'by default'];

        // only if without hedge?
        // if (($deltaWithTicker < 0) && (abs($deltaWithTicker) >= $defaultStrategyStopPriceDelta)) {return ['strategy' => StopCreate::SHORT_STOP, 'description' => 'position in loss'];}
    }

    private function getBasePriceLengthParser(BuyOrder $buyOrder): BaseStopLengthProcessorInterface
    {
        foreach ($this->baseStopLengthProviders as $processor) {
            if ($processor->supports($buyOrder)) {
                return $processor;
            }
        }

        throw new RuntimeException(
            sprintf('Cannot find appropriate BaseStopLengthProvider for BuyOrder.id = %d. Definition was: "%s"', $buyOrder->getId(), $buyOrder->getStopCreationDefinition())
        );
    }

    /**
     * @return array{StopPlacementStrategyProcessorInterface, ?string}
     */
    private function getFinalStopStrategyProcessor(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): array
    {
        $suggestionsWas = [];
        $processors = iterator_to_array($this->stopPlacementStrategyProcessors);

        $selectedProcessor = null;

        $suggestion = null;
        while ($suggestion || $processor = array_shift($processors)) {
            /** @var StopPlacementStrategyProcessorInterface $processor */
            $processor = $processor ?? $suggestion;

            try {
                // @todo return some note?
                if ($processor->supports($strategy, $context)) {
                    $selectedProcessor = $processor;
                    break;
                }
            } catch (OtherStrategySuggestionException $e) {
                if (!in_array($e->suggestion, $suggestionsWas, true)) {
                    $suggestion = $e->suggestion;
                }
            }

            $processor = null;
        }

        if (!$selectedProcessor) {
            $selectedProcessor = $this->defaultStopPlacementStrategyProcessor;
        }

        $note = null;
        if ($strategy !== StopPlacementStrategy::DEFAULT && $selectedProcessor instanceof DefaultStopPlacementStrategyProcessor) {
            $note = sprintf(
                'Cannot find appropriate StopPlacementStrategyProcessor for "%s" strategy => select DEFAULT. Context was: "%s"',
                $strategy->name,
                $context,
            );
        }

        return [$selectedProcessor, $note];
    }

    /**
     * @param iterable<BaseStopLengthProcessorInterface> $baseStopLengthProviders
     * @param iterable<StopPlacementStrategyProcessorInterface> $stopPlacementStrategyProcessors
     */
    public function __construct(
        MessageBusInterface $messageBus,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly TechnicalAnalysisToolsFactoryInterface $taProvider,
        private readonly DefaultStopPlacementStrategyProcessor $defaultStopPlacementStrategyProcessor,

        #[AutowireIterator('buy.createStopsAfterBuy.baseStopLength.processor')]
        private readonly iterable $baseStopLengthProviders,
        #[AutowireIterator('buy.createStopsAfterBuy.stopPlacementStrategy.processor')]
        private readonly iterable $stopPlacementStrategyProcessors,
    ) {
        $this->messageBus = $messageBus;
    }
}

// @todo | some logic for StopCreateStrategy::AFTER_FIRST_POSITION_STOP?
//        if ($stopStrategy === StopCreateStrategy::AFTER_FIRST_POSITION_STOP) {
//            if ($firstPositionStop = $this->stopRepository->findFirstPositionStop($position)) {
//                $basePrice = $firstPositionStop->getPrice();
//            }
//        } else
