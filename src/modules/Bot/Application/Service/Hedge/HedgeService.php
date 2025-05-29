<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Helper\Json;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;

final class HedgeService
{
    use LoggerTrait;

    public const M_POSITION_IM_PERCENT_TO_SUPPORT_MIN = 100;
    public const M_POSITION_IM_PERCENT_TO_SUPPORT_RANGES = [
        [-200, 500, 140],
        [500, 650, 120],
        [650, 800, 100],
        [800, 950, 90],
        [950, 1050, 75],
        [1050, 1200, 65],
        [1200, 1350, 60],
    ];
    public const M_POSITION_IM_PERCENT_TO_SUPPORT_DEFAULT = 50;

    public function __construct(
        private readonly StopService $stopService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        LoggerInterface $logger,
        ClockInterface $clock,
    ) {
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function getDefaultMainPositionIMPercentToSupport(Hedge $hedge): Percent
    {
        $ticker = $this->exchangeService->ticker($hedge->mainPosition->symbol);
        $currentMainPositionPnlPercent = $ticker->indexPrice->getPnlPercentFor($hedge->mainPosition);

        $mainImPercentToSupport = self::M_POSITION_IM_PERCENT_TO_SUPPORT_DEFAULT;
        foreach (self::M_POSITION_IM_PERCENT_TO_SUPPORT_RANGES as $key => [$fromPnl, $toPnl, $expectedMainImPercentToSupport]) {
            if ($key === 0 && $currentMainPositionPnlPercent < $fromPnl) {
                $mainImPercentToSupport = self::M_POSITION_IM_PERCENT_TO_SUPPORT_MIN;
                break;
            }
            if ($currentMainPositionPnlPercent >= $fromPnl && $currentMainPositionPnlPercent <= $toPnl) {
                $mainImPercentToSupport = $expectedMainImPercentToSupport;
                break;
            }
        }

        return new Percent($mainImPercentToSupport, false);
    }

    public function getApplicableSupportSize(Hedge $hedge, ?Percent $mainPositionIMPercentToSupport = null): float
    {
        $mainPositionInitialMarginPercentForSupport = $mainPositionIMPercentToSupport ?? $this->getDefaultMainPositionIMPercentToSupport($hedge);

        $applicableSupportProfit = $hedge->mainPosition->initialMargin->getPercentPart($mainPositionInitialMarginPercentForSupport);

        return $hedge->mainPosition->symbol->roundVolume($applicableSupportProfit->value() / $hedge->getPositionsDistance());
    }

    public function isSupportSizeEnoughForSupportMainPosition(Hedge $hedge, ?Percent $mainPositionIMPercentToSupport = null): bool
    {
        // @todo | what to do on short distance between positions? => 1/2 of main

        if ($this->exchangeAccountService->getCachedTotalBalance($hedge->mainPosition->symbol) < ($hedge->mainPosition->initialMargin->value() / 5)) {
            return false;
        }

        return $hedge->supportPosition->size >= $this->getApplicableSupportSize($hedge, $mainPositionIMPercentToSupport);

//        $applicableSupportRate = $applicableSupportSize / $mainPosition->size;
//        var_dump($applicableSupportSize, $applicableSupportRate);die;
//        var_dump($supportPosition->size < $applicableSupportSize);
//        $profitOfCurrentSupportOnMainPositionEntry = PnlHelper::getPnlInUsdt($supportPosition, $mainPosition->entryPrice, $supportPosition->size);
//        $profitOfApplicableSupportOnMainPositionEntry = PnlHelper::getPnlInUsdt($supportPosition, $mainPosition->entryPrice, $applicableSupportSize);
//        var_dump($profitOfCurrentSupportOnMainPositionEntry, $profitOfApplicableSupportOnMainPositionEntry);die;
    }

    public function createStopIncrementalGridBySupport(Hedge $hedge, Stop $stop): void
    {
        $supportPosition = $hedge->supportPosition;

        // For now only for support SHORT
        if ($supportPosition->side !== Side::Buy) {
            return;
        }

        $stoppedVolume = $stop->getVolume();
        $mainPositionSize = $hedge->mainPosition->size;
        $stoppedMainPositionPart = $stoppedVolume / $mainPositionSize;

        $supportPositionSize = $supportPosition->size;
        $stopVolume = $supportPosition->symbol->roundVolume($supportPositionSize * $stoppedMainPositionPart);

        $fromPrice = $stop->getPrice();

        $context = [
            'cause' => 'incrementalStopGridAfterMainPositionStopCreated',
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
        ];

        $info = $this->stopService->createIncrementalToPosition($supportPosition, $stopVolume, $fromPrice, $supportPosition->entryPrice, $context);

        $this->info(
            \sprintf(
                'IncrementalStopGrid for %s created. Info: %s.',
                $supportPosition->getCaption(),
                Json::encode([
                    'mainPosition' => ['size' => $mainPositionSize, 'stoppedVolume' => $stoppedVolume],
                    'supportPosition' => [
                        'size' => $supportPositionSize,
                        'volumeToStop' => $stopVolume
                    ],
                    'grid' => \json_encode($info),
                ])
            )
        );
    }
}
