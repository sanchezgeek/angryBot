<?php


declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;
use RuntimeException;

use function sprintf;

/**
 * @see https://www.bybit.com/en/help-center/article/Order-Cost-USDT-ContractUSDT_Perpetual_Contract
 * @see https://www.bybit.com/tr-TR/help-center/article/How-to-use-Bybit-calculator
 * @see https://www.bybit.com/en/help-center/article/Profit-Loss-calculations-USDT-ContractUSDT_Perpetual_Contract
 */
class ExecutionSandbox
{
    private ExecutionStep $currentExecStep;
    private Symbol $symbol;

    /**
     * @param Position[] $positions
     */
    public function __construct(
        private readonly OrderCostCalculator $orderCostCalculator,
        private readonly CalcPositionLiquidationPriceHandler $calcPositionLiquidationPriceHandler,
        Ticker $ticker,
        array $positions,
        CoinAmount $currentFreeBalance,
        private readonly bool $isDebugEnabled = false,
    ) {
        $this->symbol = $ticker->symbol;
        $this->currentExecStep = new ExecutionStep($ticker, $currentFreeBalance, ...$positions);
    }

    public function processOrders(SandboxBuyOrder|BuyOrder|SandboxStopOrder|Stop ...$orders): ExecutionStep
    {
        foreach ($orders as $order) {
            match (true) {
                $order instanceof SandboxBuyOrder || $order instanceof BuyOrder => $this->makeBuy($this->prepareBuyOrderDto($order)),
                $order instanceof SandboxStopOrder || $order instanceof Stop => $this->makeStop($this->prepareStopDto($order))
            };
        }

        return $this->currentExecStep;
    }

    public function getCurrentState(): ExecutionStep
    {
        return $this->currentExecStep;
    }

    /**
     * @throws SandboxInsufficientAvailableBalanceException
     */
    private function makeBuy(SandboxBuyOrder $order): void
    {
        $positionSide = $order->positionSide;
        $volume = $order->volume;
        $price = $order->price;

        $currentState = $this->currentExecStep;
        $currentState->setLastPrice($price);

        // @todo | case when position is not opened yet
        $position = $currentState->getPosition($positionSide);

        $this->notice(sprintf('__ +++ try to make buy %s on %s %s (buyOrder.price = %s) +++ __', $volume, $this->symbol->name, $positionSide->title(), $price), true);
        $orderDto = new ExchangeOrder($this->symbol, $volume, $price);
        $cost = $this->orderCostCalculator->totalBuyCost($orderDto, $position->leverage, $positionSide)->value();

        $availableBalance = $currentState->getAvailableBalance();
        if ($availableBalance->value() < $cost) {
            throw new SandboxInsufficientAvailableBalanceException(
                sprintf('Contract availableBalance balance (%s) less than order cost (%s)', $availableBalance, $cost),
            );
        }

        $this->notice(sprintf('modify free balance with %s (order cost)', -$cost)); $currentState->modifyFreeBalance(-$cost);
        $this->modifyPositionsWithBuy($position, $orderDto);
    }

    private function makeStop(SandboxStopOrder $stop): void
    {
        $positionSide = $stop->positionSide;
        $volume = $stop->volume;
        $price = $stop->price;

        $this->currentExecStep->setLastPrice($price);

        // @todo | case when position volume is not enough
        $position = $this->currentExecStep->getPosition($positionSide);

        $this->notice(sprintf('__ --- make stop %s on %s %s (stop.price = %s) --- __', $volume, $this->symbol->name, $positionSide->title(), $price), true);
        $orderDto = new ExchangeOrder($this->symbol, $volume, $price);
        $margin = $this->orderCostCalculator->orderMargin($orderDto, $position->leverage);

        $expectedPnl = PnlHelper::getPnlInUsdt($position, $price, $volume);

        $this->notice(sprintf('modify free balance with %s (expected PNL)', $expectedPnl)); $this->currentExecStep->modifyFreeBalance($expectedPnl);
        $this->notice(sprintf('modify free balance with %s (order margin)', $margin)); $this->currentExecStep->modifyFreeBalance($margin);

        // @todo | also need take into account `totaling funding fees` (https://www.bybit.com/en/help-center/article/Profit-Loss-calculations-USDT-ContractUSDT_Perpetual_Contract)

        $this->modifyPositionsWithStop($position, $orderDto);
    }

    private function modifyPositionsWithBuy(Position $current, ExchangeOrder $orderDto): void
    {
        $orderInitialMargin = $this->orderCostCalculator->orderMargin($orderDto, $current->leverage);

        $valueSum = $current->size * $current->entryPrice;
        $volumeSum = $current->size;
        $valueSum += $orderDto->getVolume() * $orderDto->getPrice()->value();
        $volumeSum += $orderDto->getVolume();

        $newEntryPrice = $valueSum / $volumeSum;
        $newValue = $newEntryPrice * $volumeSum; // @todo | only linear?

        $newInitialMargin = $current->initialMargin->add($orderInitialMargin)->value();
        $tmpPosition = new Position(
            $current->side,
            $current->symbol,
            $newEntryPrice,
            $volumeSum,
            $newValue,
            $current->liquidationPrice,
            $newInitialMargin,
            $newInitialMargin,
            $current->leverage->value(),
        );

        if ($current->oppositePosition) {
            $tmpPosition->setOppositePosition($current->oppositePosition);
        }

        $this->reCalcPositionsLiquidationAfterModify($tmpPosition);
    }

    private function modifyPositionsWithStop(Position $current, ExchangeOrder $orderDto): void
    {
        $orderInitialMargin = $this->orderCostCalculator->orderMargin($orderDto, $current->leverage);

        $entryPrice = $current->entryPrice;
        // todo | when result position size = 0 (position closed)
        $newVolume = $current->size - $orderDto->getVolume();
        $newValue = $entryPrice * $newVolume; // @todo | only linear?

        $newInitialMargin = $current->initialMargin->sub($orderInitialMargin)->value();

        $tmpPosition = new Position(
            $current->side,
            $current->symbol,
            $entryPrice,
            $newVolume,
            $newValue,
            $current->liquidationPrice,
            $newInitialMargin,
            $newInitialMargin,
            $current->leverage->value(),
        );

        if ($current->oppositePosition) {
            $tmpPosition->setOppositePosition($current->oppositePosition);
        }

        // todo | when result position size = 0 (position closed)
        $this->reCalcPositionsLiquidationAfterModify($tmpPosition);
    }

    private function reCalcPositionsLiquidationAfterModify(?Position $tmpPosition): void
    {
        if (!$tmpPosition) {
            // todo | when result position size = 0 (position closed)
            // ...
        }

        $currentFree = $this->currentExecStep->getFreeBalance();

        if ($tmpPosition->isSupportPosition()) {
            $estimatedLiquidationPrice = 0;
        } else {
            $estimatedLiquidationPrice = $this->calcPositionLiquidationPriceHandler->handle($tmpPosition, $currentFree)->estimatedLiquidationPrice()->value();
        }

        if ($tmpPosition->isSupportPosition()) {
            // recalc main position liquidation
            $mainPosition = $tmpPosition->oppositePosition;
            $mainPosition->setOppositePosition($tmpPosition, true);

            $estimatedMainPositionLiquidationPrice = $this->calcPositionLiquidationPriceHandler->handle($mainPosition, $currentFree)->estimatedLiquidationPrice()->value();
            $this->currentExecStep->setPosition(
                $mainPosition->withNewLiquidation($estimatedMainPositionLiquidationPrice),
            );

            $tmpPosition->setOppositePosition($mainPosition, true);
        }

        $this->currentExecStep->setPosition(
            $tmpPosition->withNewLiquidation($estimatedLiquidationPrice),
        );
    }

    private function prepareBuyOrderDto(SandboxBuyOrder|BuyOrder $order): SandboxBuyOrder
    {
        return $order instanceof SandboxBuyOrder ? $order : SandboxBuyOrder::fromBuyOrder($order);
    }

    private function prepareStopDto(SandboxStopOrder|Stop $stop): SandboxStopOrder
    {
        return $stop instanceof SandboxStopOrder ? $stop : SandboxStopOrder::fromStop($stop);
    }

    private function notice(string $message, bool $isBlockStart = false): void
    {
        if (!$this->isDebugEnabled) {
            return;
        }

        if ($isBlockStart) {
            OutputHelper::print('', '', $message);
        } else {
            OutputHelper::notice($message, false);
        }
    }
}