<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\BaseStopLength\Processor;

use App\Bot\Domain\Entity\BuyOrder;
use App\Buy\Application\Service\BaseStopLength\AbstractBaseStopLengthProcessor;
use App\Buy\Application\Service\BaseStopLength\BaseStopLengthProcessorInterface;
use App\Buy\Domain\Enum\PredefinedStopLengthSelector;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\PredefinedStopLength;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;

final class PredefinedStopLengthProcessor extends AbstractBaseStopLengthProcessor implements BaseStopLengthProcessorInterface
{
    public const TimeFrame DEFAULT_INTERVAL = TimeFrame::D1;
    // @todo | PredefinedStopLengthParser parameters
    public  const int DEFAULT_INTERVALS_COUNT = 4;

    public function __construct(
        private readonly TAToolsProviderInterface $taProvider,
        private readonly TimeFrame $candleInterval = self::DEFAULT_INTERVAL,
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

        $atrChangePercent = $ta->atr($this->intervalsCount)->atr->percentChange->value();

        return match ($definition->length) {
            PredefinedStopLengthSelector::VeryShort => $atrChangePercent / 5,
            PredefinedStopLengthSelector::Short => $atrChangePercent / 4,
            PredefinedStopLengthSelector::ModerateShort => $atrChangePercent / 3.5,
            PredefinedStopLengthSelector::Standard => $atrChangePercent / 3,
            PredefinedStopLengthSelector::ModerateLong => $atrChangePercent / 2.5,
            PredefinedStopLengthSelector::Long => $atrChangePercent / 2,
            PredefinedStopLengthSelector::VeryLong => $atrChangePercent,
        };
    }
}
