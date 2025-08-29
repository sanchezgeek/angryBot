<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\BaseStopLength\Processor;

use App\Bot\Domain\Entity\BuyOrder;
use App\Buy\Application\Service\BaseStopLength\AbstractBaseStopLengthProcessor;
use App\Buy\Application\Service\BaseStopLength\BaseStopLengthProcessorInterface;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\PredefinedStopLength;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Trading\Enum\TradingStyle;
use App\Domain\Value\Percent\Percent;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;

final class PredefinedStopLengthProcessor extends AbstractBaseStopLengthProcessor implements BaseStopLengthProcessorInterface
{
    // @todo | PredefinedStopLengthParser parameters
    public const TimeFrame DEFAULT_TIMEFRAME_FOR_ATR = TimeFrame::D1;
    public  const int DEFAULT_PERIOD_FOR_ATR = 4;

    public function __construct(
        private readonly TradingParametersProviderInterface $tradingParametersProvider,
        private readonly TimeFrame $timeFrame = self::DEFAULT_TIMEFRAME_FOR_ATR,
        private readonly int $atrPeriod = self::DEFAULT_PERIOD_FOR_ATR,
    ) {
    }

    public function supports(BuyOrder $buyOrder): bool
    {
        return $buyOrder->getStopCreationDefinition() instanceof PredefinedStopLength;
    }

    protected function doProcess(BuyOrder $buyOrder): float
    {
        /** @var PredefinedStopLength|null $definition */
        $definition = $buyOrder->getStopCreationDefinition();

        if ($definition === null) {
            $tradingStyle = $this->tradingParametersProvider->tradingStyle($buyOrder->getSymbol(), $buyOrder->getPositionSide());

            $length = match ($tradingStyle) {
                TradingStyle::Cautious => PriceDistanceSelector::VeryShort,
                TradingStyle::Conservative => PriceDistanceSelector::Standard,
                TradingStyle::Aggressive => PriceDistanceSelector::Long,
            };

            $definition = new PredefinedStopLength($length);
        }

        $stopDistancePricePct = $this->getStopPercent($definition, $buyOrder);

        return $stopDistancePricePct->of($buyOrder->getPrice());
    }

    private function getStopPercent(PredefinedStopLength $definition, BuyOrder $buyOrder): Percent
    {
        return $this->tradingParametersProvider->stopLength(
            $buyOrder->getSymbol(),
            $definition->length,
            $this->timeFrame,
            $this->atrPeriod
        );
    }
}
