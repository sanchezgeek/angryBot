<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyOrderPlacedTooFarFromPositionEntry;
use App\Domain\Price\SymbolPrice;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Trading\Application\Parameters\TradingDynamicParameters;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Mixin\CheckBasedOnCurrentPositionState;

/**
 * @see \App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyOnLongDistanceAndCheckAveragePriceTest
 */
final readonly class BuyOnLongDistanceAndCheckAveragePrice implements TradingCheckInterface
{
    use CheckBasedOnCurrentPositionState;

    public const PriceDistanceSelector DEFAULT_MAX_ALLOWED_PRICE_DISTANCE = PriceDistanceSelector::Long;
    public const float MAX_ALLOWED_PRICE_CHANGE_PERCENT_VALUE = 12.5;

    public const string ALIAS = 'AVG-PRICE';

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private TradingParametersProviderInterface $parameters,
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

        return $position !== null && !$position->isDummyAndFake && !$position->isPositionInLoss($context->ticker->markPrice);
    }

    public function check(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $order = self::extractMarketBuyEntryDto($orderDto);

        $position = $context->currentPositionState;
        $positionEntryPrice = $position->entryPrice();
        $tickerPrice = $context->ticker->markPrice;
        $symbol = $order->symbol;
        $positionSide = $order->positionSide;
        $riskLevel = TradingDynamicParameters::riskLevel($symbol, $positionSide);

        $percentChange = $tickerPrice->differenceWith($positionEntryPrice)->getPercentChange($order->positionSide)->abs();
        $calculatedMaxAllowedPercentChange = $this->getMaxAllowedPercentPriceChangeFromPositionEntryPrice($symbol, $riskLevel);

        $maxAllowedPercent = match($riskLevel) {
            RiskLevel::Cautious => self::MAX_ALLOWED_PRICE_CHANGE_PERCENT_VALUE / 2,
            RiskLevel::Aggressive => self::MAX_ALLOWED_PRICE_CHANGE_PERCENT_VALUE * 1.5,
            default => self::MAX_ALLOWED_PRICE_CHANGE_PERCENT_VALUE,
        };

        $maxAllowedPercentChange = new Percent(
            min($calculatedMaxAllowedPercentChange->value(), $maxAllowedPercent),
            false
        );

        $info = $this->info($tickerPrice, $positionEntryPrice, $percentChange, $maxAllowedPercentChange);

        if ($order->sourceBuyOrder->isAveragePriceCheckDisabled()) {
            return TradingCheckResult::succeed($this, sprintf('[disabled] %s', $info));
        }

        if ($percentChange->value() > $maxAllowedPercentChange->value()) {
            return BuyOrderPlacedTooFarFromPositionEntry::create($this, $positionEntryPrice, $tickerPrice, $maxAllowedPercentChange, $percentChange, $info);
        }

        return TradingCheckResult::succeed($this, $info);
    }

    private function getMaxAllowedPercentPriceChangeFromPositionEntryPrice(SymbolInterface $symbol, RiskLevel $riskLevel): Percent
    {
        $distance = match($riskLevel) {
            RiskLevel::Cautious => PriceDistanceSelector::Standard,
            RiskLevel::Aggressive => PriceDistanceSelector::VeryLong,
            default => self::DEFAULT_MAX_ALLOWED_PRICE_DISTANCE,
        };

        return $this->parameters->transformLengthToPricePercent($symbol, $distance);
    }

    private static function extractMarketBuyEntryDto(CheckOrderDto|MarketBuyCheckDto $entryDto): MarketBuyEntryDto
    {
        return $entryDto->inner;
    }

    private function info(
        SymbolPrice $orderPrice,
        SymbolPrice $positionEntryPrice,
        Percent $percentChange,
        Percent $maxAllowedPercentChange
    ): string {
        return sprintf(
            'markPrice = %s, entry=%s | %%Δ=%s, allowed%%Δ=%s',
            $orderPrice,
            $positionEntryPrice,
            $percentChange,
            $maxAllowedPercentChange,
        );
    }
}
