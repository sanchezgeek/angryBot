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

        $context = [
            'cause' => 'incrementalStopGridAfterMainPositionStopCreated',
            'onlyAfterExchangeOrderExecuted' => $stop->getExchangeOrderId(),
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
