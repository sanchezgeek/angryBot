<?php


declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\MarketBuy\Checks\MarketBuyCheckService;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\Sandbox\Assertion\PositionLiquidationIsAfterOrderPriceAssertion;
use App\Application\UseCase\Trading\Sandbox\Dto\ClosedPosition;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\ExecutionStepResult;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\OrderExecutionFailResultReason;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\OrderExecutionResult;
use App\Application\UseCase\Trading\Sandbox\Enum\SandboxErrorsHandlingType;
use App\Application\UseCase\Trading\Sandbox\Exception\AbstractSandboxExecutionFlowException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionLiquidatedBeforeOrderPriceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionNotFoundException;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;
use Exception;
use RuntimeException;

use function sprintf;

/**
 * @see https://www.bybit.com/en/help-center/article/Order-Cost-USDT-ContractUSDT_Perpetual_Contract
 * @see https://www.bybit.com/tr-TR/help-center/article/How-to-use-Bybit-calculator
 * @see https://www.bybit.com/en/help-center/article/Profit-Loss-calculations-USDT-ContractUSDT_Perpetual_Contract
 *
 * @todo Module?
 */
class TradingSandbox implements TradingSandboxInterface
{
    private SandboxStateInterface $currentState;
    private bool $considerBuyCostAsLoss = false;
    private SandboxErrorsHandlingType $errorsHandlingType = SandboxErrorsHandlingType::ThrowException;

    private array $ignoredExceptions = [];

    public function __construct(
        private readonly OrderCostCalculator $orderCostCalculator,
        private readonly CalcPositionLiquidationPriceHandler $liquidationCalculator,
        private readonly Symbol $symbol,
        private readonly bool $isDebugEnabled = false,
    ) {
    }

    /**
     * @todo replace with some `checks` array
     */
    public function addIgnoredException(string $exceptionClass): void
    {
        $this->ignoredExceptions[$exceptionClass] = true;
    }

    public function getCurrentState(): SandboxStateInterface
    {
        return (clone $this->currentState);
    }

    private ?MarketBuyCheckService $marketBuyCheckService = null;

    public function setErrorsHandlingType(SandboxErrorsHandlingType $errorsHandlingType): void
    {
        $this->errorsHandlingType = $errorsHandlingType;
    }

    public function setMarketBuyCheckService(?MarketBuyCheckService $marketBuyCheckService): void
    {
        $this->marketBuyCheckService = $marketBuyCheckService;
    }

    public function setState(SandboxStateInterface $state): void
    {
        assert($this->symbol === $state->getSymbol());

        $this->currentState = clone $state;
    }

    public function processOrders(SandboxBuyOrder|BuyOrder|SandboxStopOrder|Stop ...$orders): ExecutionStepResult
    {
        $result = new ExecutionStepResult();

        foreach ($orders as $order) {
            $result->addItem(
                match (true) {
                    $order instanceof SandboxBuyOrder || $order instanceof BuyOrder => $this->processBuy($this->prepareBuyOrderDto($order)),
                    $order instanceof SandboxStopOrder || $order instanceof Stop => $this->processStop($this->prepareStopDto($order)),
                }
            );
        }

        return $result;
    }

    private function processBuy(SandboxBuyOrder $order): OrderExecutionResult
    {
        $stateBefore = $this->getCurrentState();
        $stateAfter = $this->getCurrentState();

        $failReason = $pnl = null;
        $orderExecuted = false;

        try {
            $pnl = $this->makeBuy($order);
            $orderExecuted = true;
            $stateAfter = $this->getCurrentState();
        } catch (SandboxPositionLiquidatedBeforeOrderPriceException|BuyIsNotSafeException|SandboxInsufficientAvailableBalanceException $e) {
            $this->handleExceptionOnExecutionStep($e);
            $failReason = new OrderExecutionFailResultReason($e);
        }

        return new OrderExecutionResult($orderExecuted, $stateBefore, $order, $stateAfter, $failReason, $pnl);
    }

    private function processStop(SandboxStopOrder $stop): OrderExecutionResult
    {
        $stateBefore = $this->getCurrentState();
        $stateAfter = $this->getCurrentState();

        $failReason = $pnl = null;
        $orderExecuted = false;

        try {
            $pnl = $this->makeStop($stop);
            $orderExecuted = true;
            $stateAfter = $this->getCurrentState();
        } catch (SandboxPositionLiquidatedBeforeOrderPriceException|SandboxPositionNotFoundException $e) {
            $this->handleExceptionOnExecutionStep($e);
            $failReason = new OrderExecutionFailResultReason($e);
        }

        return new OrderExecutionResult($orderExecuted, $stateBefore, $stop, $stateAfter, $failReason, $pnl);
    }

    /**
     * @throws SandboxPositionLiquidatedBeforeOrderPriceException
     */
    private function checkPositionBeforeExecuteOrder(Side $positionSide, SandboxBuyOrder|SandboxStopOrder $order): void
    {
        $position = $this->currentState->getPosition($positionSide);

        if ($position && !$position->isSupportPosition()) {
            PositionLiquidationIsAfterOrderPriceAssertion::create($position, $order)->check();
        }
    }

    /**
     * @throws SandboxInsufficientAvailableBalanceException
     * @throws SandboxPositionLiquidatedBeforeOrderPriceException
     * @throws BuyIsNotSafeException
     */
    private function makeBuy(SandboxBuyOrder $order): ?float
    {
        $positionSide = $order->positionSide;
        $volume = $order->volume;
        $price = $order->price;
        $currentState = $this->currentState;

        # checks
        $this->checkPositionBeforeExecuteOrder($positionSide, $order);

        // @todo | undo?
        $currentState->setLastPrice($price);

        $this->notice(sprintf('__ +++ try to make buy %s on %s %s (buyOrder.price = %s) +++ __', $volume, $this->symbol->name, $positionSide->title(), $price), true);
        $orderDto = new ExchangeOrder($this->symbol, $volume, $price);
        $cost = $this->orderCostCalculator->totalBuyCost($orderDto, $this->getLeverage($positionSide), $positionSide)->value();

        $availableBalance = $currentState->getAvailableBalance()->value();
        if ($availableBalance < $cost) {
            $this->throwExceptionWhileExecute(
                SandboxInsufficientAvailableBalanceException::whenTryToBuy($order, sprintf('avail=%s < order.cost=%s', $availableBalance, $cost))
            );
        }

        /**
         * @todo
         *   (1) check even for support (e.g. PushBuyOrdersHandler will skip order if support size is already enough for support main position)
         *       @see \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler::isNeedIgnoreBuy
         *   (2) Maybe get it onto upper level?
         *       Instead of running checks right there, create MarketBuyHandler with some stubs as dependencies (that will make changes in sandbox)
         *       So ALL checks that is being performed before real buy will be applied
         */
        if ($this->marketBuyCheckService) {
            assert($order->sourceOrder !== null, new RuntimeException('To do checks in sandbox source BuyOrder must be specified'));
            $this->marketBuyCheckService->doChecks(
                MarketBuyEntryDto::fromBuyOrder($order->sourceOrder),
                $this->createTicker($price),
                $currentState,
                $this->currentState->getPosition($positionSide)
            );
        }

        $this->notice(sprintf('modify free balance with %s (order cost)', -$cost)); $currentState->modifyFreeBalance(-$cost);
        $this->modifyPositionWithBuy($positionSide, $orderDto);

        return $this->considerBuyCostAsLoss ? -$cost : 0;
    }

    /**
     * @throws SandboxPositionLiquidatedBeforeOrderPriceException|SandboxPositionNotFoundException
     */
    private function makeStop(SandboxStopOrder $stop): ?float
    {
        $positionSide = $stop->positionSide;
        $this->checkPositionBeforeExecuteOrder($positionSide, $stop);

        $volume = $stop->volume;
        $price = $stop->price;

        // @todo | undo?
        $this->currentState->setLastPrice($price);

        $this->notice(sprintf('__ --- try to make stop %s on %s %s (stop.price = %s) --- __', $volume, $this->symbol->name, $positionSide->title(), $price), true);

        $position = $this->currentState->getPosition($positionSide);
        if (!$position) {
            throw new SandboxPositionNotFoundException();
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

        return $expectedPnl;
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

    private function createTicker(float|Price $price): Ticker
    {
        $price = Price::toObj($price);

        return new Ticker($this->symbol, $price, $price, $price);
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

    public function setConsiderBuyCostAsLoss(bool $considerBuyCostAsLoss): void
    {
        $this->considerBuyCostAsLoss = $considerBuyCostAsLoss;
    }

    private function handleExceptionOnExecutionStep(Exception $exception): void
    {
        if ($this->errorsHandlingType === SandboxErrorsHandlingType::ThrowException) {
            throw $exception;
        }
    }

    private function throwExceptionWhileExecute(Exception $exception): void
    {
        if (isset($this->ignoredExceptions[get_class($exception)])) {
            return;
        }

        throw $exception;
    }
}
