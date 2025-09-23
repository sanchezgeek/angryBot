<?php

declare(strict_types=1);

namespace App\Trading\Application\Job\ApplyLockInProfit;

use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Settings\Application\Helper\SettingsHelper;
use App\Trading\Application\LockInProfit\Factory\LockInProfitStrategiesDtoFactory;
use App\Trading\Application\Settings\LockInProfit\LockInProfitSettings;
use App\Trading\Application\Settings\LockInProfit\Strategy\LinpByFixationsSettings;
use App\Trading\Application\Settings\LockInProfit\Strategy\LinpByStopsSettings;
use App\Trading\Contract\LockInProfit\LockInProfitEntry;
use App\Trading\Contract\LockInProfit\LockInProfitHandlerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ApplyLockInProfitJobJobHandler
{
    const LockInProfitSettings ROOT_SETTING = LockInProfitSettings::Enabled;

    public function __invoke(ApplyLockInProfitJob $job): void
    {
        if (SettingsHelper::exactDisabled(self::ROOT_SETTING)) {
            return;
        }

        // @todo | lockInProfit | some im threshold?

        $positions = $this->positionService->getMainPositions();
        $lastPrices = $this->positionService->getLastMarkPrices();

        foreach ($positions as $position) {
            $symbol = $position->symbol;
            $side = $position->side;
            $markPrice = $lastPrices[$symbol->name()];

            if (!SettingsHelper::enabledWithAlternatives(self::ROOT_SETTING, $symbol, $side)) {
                continue;
            }

            $entries = [];

            if (SettingsHelper::enabledWithAlternatives(LinpByStopsSettings::Enabled, $symbol, $side)) {
                $entries[] = new LockInProfitEntry($position, $markPrice, $this->strategyDtoFactory->threeStopStepsLock($position));
            }

            if (SettingsHelper::enabledWithAlternatives(LinpByFixationsSettings::Periodical_Enabled, $symbol, $side)) {
                $entries[] = new LockInProfitEntry($position, $markPrice, $this->strategyDtoFactory->defaultPeriodicalFixationsLock($position));
            }

            foreach ($entries as $entry) {
                $this->handler->handle($entry);
            }
        }
    }

    public function __construct(
        private ByBitLinearPositionService $positionService,
        private LockInProfitStrategiesDtoFactory $strategyDtoFactory,
        private LockInProfitHandlerInterface $handler,
    ) {
    }
}
