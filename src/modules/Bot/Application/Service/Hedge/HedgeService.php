<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Helper\Json;
use App\Helper\VolumeHelper;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;

final class HedgeService
{
    use LoggerTrait;

    // @todo | hedge | Mb based on current hedge distance? Because on 100pp distance it won't (and must not) work correct. Or based on distance with main position liquidation.
    const MAIN_POSITION_IM_PERCENT_FOR_SUPPORT_DEFAULT = 90;

    public function __construct(
        private readonly StopService $stopService,
        LoggerInterface $logger,
        ClockInterface $clock,
    ) {
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function getDefaultMainPositionIMPercentToSupport(): Percent
    {
        return new Percent(self::MAIN_POSITION_IM_PERCENT_FOR_SUPPORT_DEFAULT);
    }

    public function getApplicableSupportSize(Hedge $hedge, ?Percent $mainPositionIMPercentToSupport = null): float
    {
        $mainPositionInitialMarginPercentForSupport = $mainPositionIMPercentToSupport ?? $this->getDefaultMainPositionIMPercentToSupport();

        $applicableSupportProfit = $hedge->mainPosition->initialMargin->getPercentPart($mainPositionInitialMarginPercentForSupport);

        return VolumeHelper::round($applicableSupportProfit->value() / $hedge->getPositionsDistance());
    }

    public function isSupportSizeEnoughForSupportMainPosition(Hedge $hedge, Percent $mainPositionIMPercentToSupport = null): bool
    {
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
        $stopVolume = VolumeHelper::round($supportPositionSize * $stoppedMainPositionPart);

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
