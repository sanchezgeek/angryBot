<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Handler\UnexpectedSandboxExecutionExceptionHandler;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Buy\Application\Helper\BuyOrderInfoHelper;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyOrderPlacedTooFarFromPositionEntry;
use App\Domain\Price\SymbolPrice;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Mixin\CheckBasedOnCurrentPositionState;
use App\Trading\SDK\Check\Mixin\CheckBasedOnExecutionInSandbox;

/**
 * @see \App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyOnLongDistanceAndCheckAveragePriceTest
 */
final readonly class BuyOnLongDistanceAndCheckAveragePrice implements TradingCheckInterface
{
    use CheckBasedOnExecutionInSandbox;
    use CheckBasedOnCurrentPositionState;

    public const PredefinedStopLengthSelector DEFAULT_MAX_ALLOWED_PRICE_CHANGE = PredefinedStopLengthSelector::Long;
    public const int MAX_ALLOWED_PRICE_CHANGE_PERCENT_VALUE = 10;

    public const string ALIAS = 'BUY/AVG-PRICE_check';

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private TradingParametersProviderInterface $parameters,
        private UnexpectedSandboxExecutionExceptionHandler $unexpectedSandboxExceptionHandler,
        PositionServiceInterface $positionService,
    ) {
        $this->initPositionService($positionService);
    }

    public function alias(): string
    {
        return self::ALIAS;
    }

    public function supports(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): bool
    {
        $orderDto = self::extractMarketBuyEntryDto($orderDto);

        if (!$orderDto->sourceBuyOrder) {
            return false;
        }

        // @todo | buy | check | only for `\App\Bot\Domain\Entity\Stop::IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER` ?

        // @todo | check | может всё таки пускать дальше, чтобы потом в логах видеть почему allowed? Хотя возможно это лучше в базу. Anyway, возможно лучше проверку disabled внутри supports не делать
        $checkMustBeSkipped = $orderDto->sourceBuyOrder->isAveragePriceCheckDisabled();
        if ($checkMustBeSkipped) {
            return false;
        }

        $this->enrichContextWithCurrentPositionState($orderDto->symbol, $orderDto->positionSide, $context);
        $position = $context->currentPositionState;

        return $position !== null && !$position->isPositionInLoss($context->ticker->markPrice);
    }

    public function check(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $order = self::extractMarketBuyEntryDto($orderDto);

        $position = $context->currentPositionState;
        $positionEntryPrice = $position->entryPrice();
        $orderPrice = $context->ticker->markPrice;

        $percentChange = $orderPrice->differenceWith($positionEntryPrice)->getPercentChange($order->positionSide)->abs();
        $calculatedMaxAllowedPercentChange = $this->getMaxAllowedPercentPriceChangeFromPositionEntryPrice($order->symbol);

        $maxAllowedPercentChange = new Percent(
            min($calculatedMaxAllowedPercentChange->value(), self::MAX_ALLOWED_PRICE_CHANGE_PERCENT_VALUE),
            false
        );

        $info = $this->info($position, $order, $orderPrice, $positionEntryPrice, $percentChange, $maxAllowedPercentChange);

        if ($order->sourceBuyOrder->isAveragePriceCheckDisabled()) {
            return TradingCheckResult::succeed($this, sprintf('[disabled] %s', $info));
        }

        if ($percentChange->value() > $maxAllowedPercentChange->value()) {
            return BuyOrderPlacedTooFarFromPositionEntry::create($this, $positionEntryPrice, $orderPrice, $maxAllowedPercentChange, $percentChange, $info);
        }

        return TradingCheckResult::succeed($this, $info);
    }

    private function getMaxAllowedPercentPriceChangeFromPositionEntryPrice(SymbolInterface $symbol): Percent
    {
        $length = self::DEFAULT_MAX_ALLOWED_PRICE_CHANGE;

        return $this->parameters->regularOppositeBuyOrderLength($symbol, $length);
    }

    private static function extractMarketBuyEntryDto(CheckOrderDto|MarketBuyCheckDto $entryDto): MarketBuyEntryDto
    {
        return $entryDto->inner;
    }

    private function info(
        Position $position,
        MarketBuyEntryDto $order,
        SymbolPrice $orderPrice,
        SymbolPrice $positionEntryPrice,
        Percent $percentChange,
        Percent $maxAllowedPercentChange
    ): string {
        return sprintf(
            '%s | %s (%s) | entry=%s | %%Δ=%s, allowed%%Δ=%s',
            $position,
            BuyOrderInfoHelper::identifier($order->sourceBuyOrder),
            BuyOrderInfoHelper::shortInlineInfo($order->volume, $orderPrice),
            $positionEntryPrice,
            $percentChange,
            $maxAllowedPercentChange,
        );
    }
}
