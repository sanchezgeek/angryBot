<?php

declare(strict_types=1);

namespace App\Stop\Application\Job\MoveOpenedPositionStopsToBreakeven;

use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Stop\Application\Settings\MovePositionStopsSettings;
use App\Stop\Application\UseCase\MoveStopsToBreakeven\MoveStopsToBreakevenEntryDto;
use App\Stop\Application\UseCase\MoveStopsToBreakeven\MoveStopsToBreakevenHandlerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MoveOpenedPositionStopsToBreakevenHandler
{
    public function __construct(
        private ByBitLinearPositionService $positionService,
        private MoveStopsToBreakevenHandlerInterface $moveStopsToBreakevenHandler,
        private AppSettingsProviderInterface $settings,
    ) {
    }

    public function __invoke(MoveOpenedPositionStopsToBreakeven $job): void
    {
        $moveAfterPositionPnlOverride = $job->pnlGreaterThan;
        $targetPositionPnlPercent = $job->targetPositionPnlPercent;
        $excludeFixationsStop = $job->excludeFixationsStop;

        $allPositions = $this->positionService->getAllPositions();
        $lastPrices = $this->positionService->getLastMarkPrices();

        $candidates = [];
        foreach ($allPositions as $symbolRaw => $positions) {
            $markPrice = $lastPrices[$symbolRaw];

            foreach ($positions as $position) {
                $moveAfterPositionPnl = $moveAfterPositionPnlOverride ?? $this->settings->required(
                    SettingAccessor::withAlternativesAllowed(MovePositionStopsSettings::MoveToBreakeven_After_PositionPnlPercent, $position->symbol, $position->side)
                );

                $pnlPercent = $markPrice->getPnlPercentFor($position);
                if ($pnlPercent < $moveAfterPositionPnl) {
                    continue;
                }

                $candidates[] = $position;
            }
        }

        foreach ($candidates as $candidate) {
            $this->moveStopsToBreakevenHandler->handle(
                new MoveStopsToBreakevenEntryDto(
                    $candidate,
                    $targetPositionPnlPercent,
                    $excludeFixationsStop
                )
            );
        }
    }
}
