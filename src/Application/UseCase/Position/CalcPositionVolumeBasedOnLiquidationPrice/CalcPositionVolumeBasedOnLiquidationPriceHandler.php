<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionVolumeBasedOnLiquidationPrice;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactory;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;
use App\Tests\Factory\TickerFactory;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class CalcPositionVolumeBasedOnLiquidationPriceHandler
{
    public function __construct(
        private OrderCostCalculator $orderCostCalculator,
        private CalcPositionLiquidationPriceHandler $liquidationCalculator,
        private TradingSandboxFactory $sandboxFactory,
        private LoggerInterface $appErrorLogger,
        private RateLimiterFactory $calcNewVolumeBasedOnWishedLiquidationPriceNoticeThrottlingLimiter
    ) {
    }

    public function handle(CalcPositionVolumeBasedOnLiquidationPriceEntryDto $input): CalcPositionVolumeBasedOnLiquidationPriceResult
    {
        $initialPosition = $input->initialPositionState;
        $symbol = $initialPosition->symbol;
        $freeContractBalanceForCalcLiquidation = $input->freeContractBalanceForCalcLiquidation;
        $wishedLiquidationPrice = $input->wishedLiquidationPrice;
        $currentPrice = $input->currentPrice;
        $notCoveredSize = $initialPosition->getNotCoveredSize();

        self::checkPrerequisites($input);

        $fundsAvailableForLiquidation = $freeContractBalanceForCalcLiquidation->value();
        if (($hedge = $initialPosition->getHedge())?->isProfitableHedge()) {
            $fundsAvailableForLiquidation += $hedge->getSupportProfitOnMainEntryPrice();
        }

        $maintenanceMarginLiquidationDistance = CalcPositionLiquidationPriceHandler::getMaintenanceMarginLiquidationDistance($initialPosition);
        $resultNotCoveredSize = $fundsAvailableForLiquidation / ($wishedLiquidationPrice->differenceWith($initialPosition->entryPrice())->absDelta() - $maintenanceMarginLiquidationDistance);

        $supportPosition = $initialPosition->getHedge()?->supportPosition;
        $resultSize = $supportPosition ? $resultNotCoveredSize + $supportPosition->size : $resultNotCoveredSize;
        $roundedResultSize = $symbol->roundVolumeDown($resultSize);

        $currentSize = $initialPosition->size;

        $diffRaw = $currentSize - $resultSize;
        $diffRounded = $symbol->roundVolumeDown($diffRaw);
        // @todo | try up?

        // note: $NEW!freeContractBalanceForCalcLiquidation
        [$newPositionState, $freeContractBalanceForCalcLiquidation] = $this->reducePositionAndRecalculateLiquidation($initialPosition, $currentPrice, $diffRounded, $input->contractBalance);
        $recalculatedPositionLiquidation = $newPositionState->liquidationPrice();
        // ---------------
        $releasedIM = $this->orderCostCalculator->orderMargin(
            new ExchangeOrder($symbol, $diffRaw, $initialPosition->entryPrice()),
            $initialPosition->leverage
        );
        $expectedPnl = PnlHelper::getPnlInUsdt($initialPosition, $currentPrice, $diffRaw);

        $balanceDiff = $releasedIM->value() + $expectedPnl;

        $diffInLiquidationPrice = $recalculatedPositionLiquidation->differenceWith($wishedLiquidationPrice);

        if (!($diffInLiquidationPrice->absDelta() > 0.0004 * $initialPosition->entryPrice)) {
            return new CalcPositionVolumeBasedOnLiquidationPriceResult(
                $roundedResultSize,
                $diffRounded,
                $recalculatedPositionLiquidation
            );
        }

        if (
            $diffInLiquidationPrice->isProfitFor($initialPosition->side)
            && $balanceDiff < 0
        ) {
            $msg = sprintf(
                'incorrect logic? result liquidation (%s) is greater than wished (%s), but balance changed with %s',
                $recalculatedPositionLiquidation,
                $wishedLiquidationPrice,
                $balanceDiff
            );

            $this->logNotice($initialPosition, $msg);
            OutputHelper::print($msg);
        }

        $estimatedDiffInVolume = abs($balanceDiff / $diffInLiquidationPrice->absDelta());

        $k = $diffInLiquidationPrice->absDelta() / 1000;
        $estimatedDiffInVolume = $estimatedDiffInVolume * $k;
        $recalculationsCount = 50;

        if ($recalculationsCount * $symbol->minOrderQty() >= $estimatedDiffInVolume) {
            $volumeModifier = $symbol->minOrderQty();
            $recalculationsCount = floor($estimatedDiffInVolume / $volumeModifier);
        }

        $minRecalculationsCount = 10;

        if ($recalculationsCount < $minRecalculationsCount) {
            $recalculationsCount = $minRecalculationsCount;
        }

        $volumeModifier = $estimatedDiffInVolume / $recalculationsCount;

        $sign = $diffInLiquidationPrice->isProfitFor($initialPosition->side) ? -1 : 1;
        $volumeModifier = $sign * $volumeModifier;

        $orderMargin = $this->orderCostCalculator->orderMargin(new ExchangeOrder($symbol, abs($volumeModifier), $initialPosition->entryPrice()), $initialPosition->leverage)->value();
        $imModifier = $volumeModifier < 0 ? -$orderMargin : $orderMargin;
        $freeBalanceModifier = $volumeModifier < 0 ? $orderMargin : -$orderMargin;

        $positionSide = $initialPosition->side;

        $initialDiffInLiquidationPrice = $diffInLiquidationPrice;
        $oppositeDirection = $initialDiffInLiquidationPrice->movementDirection($positionSide);

        while ($recalculationsCount) {
            $prevPositionState = $newPositionState;
            $prevFreeContractBalanceForCalcLiquidation = $freeContractBalanceForCalcLiquidation;
//            echo $newPositionState->liquidationPrice() . ' ->      ';

            $freeContractBalanceForCalcLiquidation = $freeContractBalanceForCalcLiquidation->add($freeBalanceModifier);

            $newPositionState = PositionClone::full($newPositionState)
                ->withSize($newPositionState->size + $volumeModifier)
                ->withInitialMargin($newPositionState->initialMargin->add($imModifier))
                ->create();

            $recalculatedPositionLiquidation = $this->liquidationCalculator->handle($newPositionState, $freeContractBalanceForCalcLiquidation)->estimatedLiquidationPrice();

            $newPositionState = PositionClone::full($newPositionState)
                ->withLiquidation($recalculatedPositionLiquidation->value())
                ->create();

//            echo $recalculatedPositionLiquidation . PHP_EOL;

            $recalculationsCount--;
            $diffInLiquidationPrice = $recalculatedPositionLiquidation->differenceWith($wishedLiquidationPrice);
            $diffWithWished = $recalculatedPositionLiquidation->differenceWith($wishedLiquidationPrice);

            if ($diffInLiquidationPrice->absDelta() < 0.0003 * $initialPosition->entryPrice) {
                break;
            }

            if ($oppositeDirection !== $diffWithWished->movementDirection($positionSide)) {
//                var_dump('back');
                $newPositionState = $prevPositionState;
                $freeContractBalanceForCalcLiquidation = $prevFreeContractBalanceForCalcLiquidation;

                $volumeModifier = ($volumeModifier / 1.5);

                $imModifier = $imModifier / 1.5;
                $freeBalanceModifier = $freeBalanceModifier / 1.5;
            }
        }

        $resultSize = $newPositionState->size;
        $currentSize = $initialPosition->size;

        $diffRounded = $symbol->roundVolumeDown($currentSize - $resultSize);

        return new CalcPositionVolumeBasedOnLiquidationPriceResult(
            $roundedResultSize,
            $diffRounded,
            $recalculatedPositionLiquidation
        );

//        if ($position->isLong() && $liquidationDistance >= $position->entryPrice) return new CalcPositionVolumeBasedOnLiquidationPriceResult($position->entryPrice(), Price::float(0));
//        $estimatedLiquidationPrice = $position->entryPrice()->modifyByDirection($position->side, PriceMovementDirection::TO_LOSS, $liquidationDistance);
    }

    /**
     * @return array{Position, CoinAmount}
     */
    private function reducePositionAndRecalculateLiquidation(Position $currentPositionState, Price $currentPrice, float $qty, ContractBalance $contractBalance): array
    {
        $symbol = $currentPositionState->symbol;
        $ticker = TickerFactory::withEqualPrices($symbol, $currentPrice->value());
        $sandbox = $this->sandboxFactory->empty($symbol);
        $positions = [$currentPositionState]; if ($currentPositionState->oppositePosition) $positions[] = $currentPositionState->oppositePosition;
        $sandbox->setState(new SandboxState($ticker, $contractBalance,... $positions));
        $sandbox->processOrders(new SandboxStopOrder($symbol, $currentPositionState->side, $currentPrice->value(), $qty));

        return [
            $sandbox->getCurrentState()->getPosition($currentPositionState->side),
            $sandbox->getCurrentState()->getFreeBalanceForLiq(),
        ];
    }

    /**
     * @throws LogicException
     */
    private static function checkPrerequisites(CalcPositionVolumeBasedOnLiquidationPriceEntryDto $input): void
    {
        $position = $input->initialPositionState;
        $currentLiquidationPrice = $position->liquidationPrice();
        $wishedLiquidationPrice = $input->wishedLiquidationPrice;
        $currentPrice = $input->currentPrice;

        if ($position->isSupportPosition()) {
            throw new LogicException(__CLASS__ . ': incorrect usage (support position cannot be under liquidation)');
        }

        if (!$wishedLiquidationPrice->differenceWith($currentLiquidationPrice)->isLossFor($position->side)) {
            throw new LogicException(__CLASS__ . ': incorrect usage (wishedLiquidationPrice must be placed after current liquidation price)');
        }

        if ($wishedLiquidationPrice->differenceWith($currentPrice)->isProfitFor($position->side)) {
            throw new LogicException(__CLASS__ . ': incorrect usage (wishedLiquidationPrice must be placed before current asset price)');
        }
    }

    private function logNotice(Position $position, string $msg): void
    {
//        if (!$this->calcNewVolumeBasedOnWishedLiquidationPriceNoticeThrottlingLimiter->create($position->getCaption())->consume()->isAccepted()) {
//            return;
//        }

        $this->appErrorLogger->critical(sprintf('%s / %s', __CLASS__, $msg));
    }
}
