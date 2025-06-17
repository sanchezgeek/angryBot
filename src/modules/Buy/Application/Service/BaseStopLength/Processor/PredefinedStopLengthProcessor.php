<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\BaseStopLength\Processor;

use App\Bot\Domain\Entity\BuyOrder;
use App\Buy\Application\Service\BaseStopLength\AbstractBaseStopLengthProcessor;
use App\Buy\Application\Service\BaseStopLength\BaseStopLengthProcessorInterface;
use App\Buy\Domain\Enum\PredefinedStopLengthSelector;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\PredefinedStopLength;
use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Application\Contract\TechnicalAnalysisToolsFactoryInterface;

final class PredefinedStopLengthProcessor extends AbstractBaseStopLengthProcessor implements BaseStopLengthProcessorInterface
{
    public const CandleIntervalEnum DEFAULT_INTERVAL = CandleIntervalEnum::D1;
    // @todo | PredefinedStopLengthParser parameters
    public  const int DEFAULT_INTERVALS_COUNT = 4;

    public function __construct(
        private readonly TechnicalAnalysisToolsFactoryInterface $taProvider,
        private readonly CandleIntervalEnum $candleInterval = self::DEFAULT_INTERVAL,
        private readonly int $intervalsCount = self::DEFAULT_INTERVALS_COUNT,
    ) {
    }

    public function supports(BuyOrder $buyOrder): bool
    {
        return $buyOrder->getStopCreationDefinition() instanceof PredefinedStopLength;
    }

    protected function doProcess(BuyOrder $buyOrder): float
    {
        /** @var PredefinedStopLength $definition */
        $definition = $buyOrder->getStopCreationDefinition();

        $stopDistancePricePct = $this->getStopPercent($definition, $buyOrder);

        return new Percent($stopDistancePricePct, false)->of($buyOrder->getPrice());
    }

    private function getStopPercent(PredefinedStopLength $definition, BuyOrder $buyOrder): float
    {
        $ta = $this->taProvider->create($buyOrder->getSymbol(), $this->candleInterval);

        $averagePriceChangePercent = $ta->averagePriceChangePrev($this->intervalsCount)->averagePriceChange->percentChange->value();

        return match ($definition->length) {
            PredefinedStopLengthSelector::VeryShort => $averagePriceChangePercent / 5,
            PredefinedStopLengthSelector::Short => $averagePriceChangePercent / 4,
            PredefinedStopLengthSelector::ModerateShort => $averagePriceChangePercent / 3.5,
            PredefinedStopLengthSelector::Standard => $averagePriceChangePercent / 3,
            PredefinedStopLengthSelector::ModerateLong => $averagePriceChangePercent / 2.5,
            PredefinedStopLengthSelector::Long => $averagePriceChangePercent / 2,
            PredefinedStopLengthSelector::VeryLong => $averagePriceChangePercent,
        };
    }
}
