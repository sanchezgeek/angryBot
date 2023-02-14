<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Hedge;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Service\Stop\StopService;
use App\Helper\Json;
use App\Helper\VolumeHelper;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;

final class HedgeService
{
    use LoggerTrait;

    // private const DEFAULT_INC = 0.001;
    private const DEFAULT_STEP = 11;
    private const DEFAULT_TRIGGER_DELTA = 3;

    public function __construct(
        private readonly StopService $stopService,
        LoggerInterface $logger,
    ) {
        $this->logger = $logger;
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
        $toPrice = $supportPosition->entryPrice;

        $context = [
            'cause' => 'incrementalStopGridAfterMainPositionStopCreated',
            'onlyAfterExchangeOrderExecuted' => $stop->getExchangeOrderId(),
            'uniqid' => ($uniqId = \uniqid('inc-stop', true))
        ];

        $delta = $fromPrice - $toPrice;
        $step = self::DEFAULT_STEP;

        $count = \ceil($delta / $step);
        $stepVolume = VolumeHelper::round($stopVolume / $count);

        $this->info(
            \sprintf(
                'Create IncrementalStopGrid for %s. Info: %s.',
                $supportPosition->getCaption(),
                Json::encode([
                    'mainPosition' => ['size' => $mainPositionSize, 'stoppedVolume' => $stoppedVolume],
                    'supportPosition' => [
                        'size' => $supportPositionSize,
                        'volumeToStop' => $stopVolume
                    ],
                    'delta' => $delta,
                    'step' => $step,
                    'count' => $count,
                    'stepVolume' => $stepVolume,
                    'uniqueID' => $uniqId,
                ])
            )
        );

        // ---------------- //

        $volume = $stepVolume;
        $price = $fromPrice;

        do {
            $this->stopService->create(
                $supportPosition->side,
                $price,
                $volume,
                self::DEFAULT_TRIGGER_DELTA,
                $context
            );

            $volume += $stepVolume;
            $stopVolume -= $stepVolume;
            $price -= $step;
        } while ($stopVolume >= $stepVolume && $price >= $toPrice);

        echo (
            \sprintf(
                '!!!! IncrementalStopGrid for %s created. UniqueID: %s',
                $supportPosition->getCaption(),
                $uniqId
            )
        );
    }
}
