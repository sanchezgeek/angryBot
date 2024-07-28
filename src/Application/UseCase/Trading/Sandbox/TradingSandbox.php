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
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;

use function sprintf;

/**
 * @see https://www.bybit.com/en/help-center/article/Order-Cost-USDT-ContractUSDT_Perpetual_Contract
 * @see https://www.bybit.com/tr-TR/help-center/article/How-to-use-Bybit-calculator
 * @see https://www.bybit.com/en/help-center/article/Profit-Loss-calculations-USDT-ContractUSDT_Perpetual_Contract
 */
class TradingSandbox implements TradingSandboxInterface
{
    private SandboxState $currentState;

    public function __construct(
        private readonly OrderCostCalculator $orderCostCalculator,
        private readonly CalcPositionLiquidationPriceHandler $calcPositionLiquidationPriceHandler,
        private readonly Symbol $symbol,
        private readonly bool $isDebugEnabled = false,
    ) {}

    public function setState(SandboxState $state): self
    {
        assert($this->symbol === $state->symbol);
        $this->currentState = $state;

        return $this;
    }

    /**
     * @throws SandboxInsufficientAvailableBalanceException
     */
    public function processOrders(SandboxBuyOrder|BuyOrder|SandboxStopOrder|Stop ...$orders): SandboxState
    {
        foreach ($orders as $order) {
            match (true) {
                $order instanceof SandboxBuyOrder || $order instanceof BuyOrder => $this->makeBuy($this->prepareBuyOrderDto($order)),
                $order instanceof SandboxStopOrder || $order instanceof Stop => $this->makeStop($this->prepareStopDto($order))
            };
        }

        return $this->currentState;
    }

    public function getCurrentState(): SandboxState
    {
        return $this->currentState;
    }

    /**
     * @throws SandboxInsufficientAvailableBalanceException
     */
    private function makeBuy(SandboxBuyOrder $order): void
    {
        $positionSide = $order->positionSide;
        $volume = $order->volume;
        $price = $order->price;

        $currentState = $this->currentState;
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

        $this->currentState->setLastPrice($price);

        // @todo | case when position volume is not enough
        $position = $this->currentState->getPosition($positionSide);

        $this->notice(sprintf('__ --- make stop %s on %s %s (stop.price = %s) --- __', $volume, $this->symbol->name, $positionSide->title(), $price), true);
        $orderDto = new ExchangeOrder($this->symbol, $volume, $price);
        $margin = $this->orderCostCalculator->orderMargin($orderDto, $position->leverage);

        $expectedPnl = PnlHelper::getPnlInUsdt($position, $price, $volume);

        $this->notice(sprintf('modify free balance with %s (expected PNL)', $expectedPnl)); $this->currentState->modifyFreeBalance($expectedPnl);
        $this->notice(sprintf('modify free balance with %s (order margin)', $margin)); $this->currentState->modifyFreeBalance($margin);

        // @todo | also need take into account `totaling funding fees` (https://www.bybit.com/en/help-center/article/Profit-Loss-calculations-USDT-ContractUSDT_Perpetual_Contract)

        $this->modifyPositionsWithStop($position, $orderDto);
    }

    private function modifyPositionsWithBuy(Position $current, ExchangeOrder $orderDto): void
    {
        $valueSum = $current->size * $current->entryPrice;
        $volumeSum = $current->size;

        $valueSum += $orderDto->getVolume() * $orderDto->getPrice()->value();
        $volumeSum += $orderDto->getVolume();

        $position = PositionClone::of($current)->withEntry($valueSum / $volumeSum)->withSize($volumeSum)->create();

        $this->reCalcPositionsLiquidationAfterModify($position);
    }

    private function modifyPositionsWithStop(Position $current, ExchangeOrder $orderDto): void
    {
        // todo | when result position size = 0 (position closed)
        $position = PositionClone::of($current)->withSize($current->size - $orderDto->getVolume())->create();

        $this->reCalcPositionsLiquidationAfterModify($position);
    }

    private function reCalcPositionsLiquidationAfterModify(?Position $tmpPosition): void
    {
        if (!$tmpPosition) {
            // todo | when result position size = 0 (position closed)
            // ...
        }

        $currentFree = $this->currentState->getFreeBalance();

        if ($tmpPosition->isSupportPosition()) {
            $estimatedLiquidationPrice = 0;
        } else {
            $estimatedLiquidationPrice = $this->calcPositionLiquidationPriceHandler->handle($tmpPosition, $currentFree)->estimatedLiquidationPrice()->value();
        }

        # recalculate main position liquidation
        if ($tmpPosition->isSupportPosition()) {
            $mainPosition = $tmpPosition->oppositePosition;
            $mainPosition->setOppositePosition($tmpPosition, true);

            $estimatedMainPositionLiquidationPrice = $this->calcPositionLiquidationPriceHandler->handle($mainPosition, $currentFree)->estimatedLiquidationPrice()->value();
            $this->currentState->setPosition(
                PositionClone::of($mainPosition)->withLiquidation($estimatedMainPositionLiquidationPrice)->create(),
            );

            $tmpPosition->setOppositePosition($mainPosition, true);
        }

        $this->currentState->setPosition(
            PositionClone::of($tmpPosition)->withLiquidation($estimatedLiquidationPrice)->create(),
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