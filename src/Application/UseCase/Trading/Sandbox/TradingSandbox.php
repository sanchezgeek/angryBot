<?php


declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\Sandbox\Assertion\PositionLiquidationIsAfterOrderPriceAssertion;
use App\Application\UseCase\Trading\Sandbox\Dto\ClosedPosition;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\PositionLiquidatedBeforeOrderPriceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
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
        private readonly CalcPositionLiquidationPriceHandler $liquidationCalculator,
        private readonly Symbol $symbol,
        private readonly bool $isDebugEnabled = false,
    ) {}

    public function setState(SandboxState $state): self
    {
        assert($this->symbol === $state->symbol);
        $this->currentState = clone $state;

        return $this;
    }

    public function processOrders(SandboxBuyOrder|BuyOrder|SandboxStopOrder|Stop ...$orders): SandboxState
    {
        foreach ($orders as $order) {
            match (true) {
                $order instanceof SandboxBuyOrder || $order instanceof BuyOrder => $this->makeBuy($this->prepareBuyOrderDto($order)),
                $order instanceof SandboxStopOrder || $order instanceof Stop => $this->makeStop($this->prepareStopDto($order)),
            };
        }

        return $this->currentState;
    }

    /**
     * @throws PositionLiquidatedBeforeOrderPriceException
     */
    private function checkPositionBeforeExecuteOrder(Side $positionSide, SandboxBuyOrder|SandboxStopOrder $order): void
    {
        $position = $this->currentState->getPosition($positionSide);

        if ($position && !$position->isSupportPosition()) {
            PositionLiquidationIsAfterOrderPriceAssertion::create($position, $order)->check();
        }
    }

    public function getCurrentState(): SandboxState
    {
        return $this->currentState;
    }

    /**
     * @throws SandboxInsufficientAvailableBalanceException
     * @throws PositionLiquidatedBeforeOrderPriceException
     */
    private function makeBuy(SandboxBuyOrder $order): void
    {
        $positionSide = $order->positionSide;
        $this->checkPositionBeforeExecuteOrder($positionSide, $order);

        $volume = $order->volume;
        $price = $order->price;

        $currentState = $this->currentState;
        $currentState->setLastPrice($price);

        $this->notice(sprintf('__ +++ try to make buy %s on %s %s (buyOrder.price = %s) +++ __', $volume, $this->symbol->name, $positionSide->title(), $price), true);
        $orderDto = new ExchangeOrder($this->symbol, $volume, $price);
        $cost = $this->orderCostCalculator->totalBuyCost($orderDto, $this->getLeverage($positionSide), $positionSide)->value();

        $availableBalance = $currentState->getAvailableBalance()->value();
        if ($availableBalance < $cost) {
            throw SandboxInsufficientAvailableBalanceException::whenTryToBuy($order, sprintf('balance.avail [%s] less than order.cost [%s]', $availableBalance, $cost));
        }

        $this->notice(sprintf('modify free balance with %s (order cost)', -$cost)); $currentState->modifyFreeBalance(-$cost);
        $this->modifyPositionWithBuy($positionSide, $orderDto);
    }

    /**
     * @throws PositionLiquidatedBeforeOrderPriceException
     */
    private function makeStop(SandboxStopOrder $stop): void
    {
        $positionSide = $stop->positionSide;
        $this->checkPositionBeforeExecuteOrder($positionSide, $stop);

        $volume = $stop->volume;
        $price = $stop->price;

        $this->currentState->setLastPrice($price);

        $this->notice(sprintf('__ --- try to make stop %s on %s %s (stop.price = %s) --- __', $volume, $this->symbol->name, $positionSide->title(), $price), true);

        $position = $this->currentState->getPosition($positionSide);
        if (!$position) {
            // @todo | sandbox | exception?
            $this->notice(sprintf('%s %s position not found. Position closed? Cannot make stop. Skip', $this->symbol->name, $positionSide->title()));
            return;
        }

        if ($position->size < $volume) {
            $this->notice(sprintf('%s position size less than provided volume (%s). Use position rest size (%s) as order volume.', $position->getCaption(), $volume, $position->size));
            $volume = $position->size;
        }

        $orderDto = new ExchangeOrder($this->symbol, $volume, $price);
        $margin = $this->orderCostCalculator->orderMargin($orderDto, $position->leverage);

        $expectedPnl = PnlHelper::getPnlInUsdt($position, $price, $volume);

        $this->notice(sprintf('modify free balance with %s (expected PNL)', $expectedPnl)); $this->currentState->modifyFreeBalance($expectedPnl);
        $this->notice(sprintf('modify free balance with %s (order margin)', $margin)); $this->currentState->modifyFreeBalance($margin);

        // @todo | also need take into account `totaling funding fees` (https://www.bybit.com/en/help-center/article/Profit-Loss-calculations-USDT-ContractUSDT_Perpetual_Contract)

        $this->modifyPositionWithStop($position, $volume);
    }

    private function modifyPositionWithBuy(Side $positionSide, ExchangeOrder $orderDto): void
    {
        if ($current = $this->currentState->getPosition($positionSide)) {
            $valueSum = $current->size * $current->entryPrice;
            $volumeSum = $current->size;

            $valueSum += $orderDto->getVolume() * $orderDto->getPrice()->value();
            $volumeSum += $orderDto->getVolume();

            $position = PositionClone::full($current)->withEntry($valueSum / $volumeSum)->withSize($volumeSum)->create();
        } else {
            $position = $this->openPosition($positionSide, $orderDto);
        }

        $this->actualizePositions($position);
    }

    private function modifyPositionWithStop(Position $current, float $volume): void
    {
        try {
            $position = PositionClone::clean($current)->withSize($current->size - $volume)->create();
        } catch (SizeCannotBeLessOrEqualsZeroException) {
            $this->notice(sprintf('!!!! %s position closed !!!!', $current->getCaption()));
            $position = new ClosedPosition($current->side, $current->symbol);
        }

        $this->actualizePositions($position);
    }

    private function actualizePositions(Position|ClosedPosition $modifiedPosition): void
    {
        $this->currentState->setPositionAndActualizeOpposite($modifiedPosition);
        $withActualOpposite = $this->currentState->getPosition($modifiedPosition->side);
        if ($withActualOpposite instanceof Position) {
            $this->actualizePositionLiquidation($withActualOpposite);
        }

        if ($opposite = $this->currentState->getPosition($modifiedPosition->side->getOpposite())) {
            $this->actualizePositionLiquidation($opposite);
        }
    }

    private function actualizePositionLiquidation(Position $position): void
    {
        $liquidation = $position->isSupportPosition() ? 0 : $this->liquidationCalculator->handle($position, $this->currentState->getFreeBalance())->estimatedLiquidationPrice()->value();

        $actualizedWithCalculatedLiquidation = PositionClone::clean($position)->withLiquidation($liquidation)->create();
        $this->currentState->setPositionAndActualizeOpposite($actualizedWithCalculatedLiquidation);
    }

    /**
     * @todo | Pass param
     */
    private function getLeverage(Side $side): Leverage
    {
        return $this->currentState->getPosition($side)?->leverage ?? new Leverage(100);
    }

    private function openPosition(Side $side, ExchangeOrder $orderDto): Position
    {
        $leverage = $this->getLeverage($side);
        $entry = $orderDto->getPrice()->value();
        $size = $orderDto->getVolume();
        $positionValue = $entry * $size;
        $positionBalance = $initialMargin = (new CoinAmount($this->symbol->associatedCoin(), $positionValue / $leverage->value()))->value();

        return new Position($side, $this->symbol, $entry, $size, $positionValue, 0, $initialMargin, $positionBalance, $leverage->value());
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
