<?php

declare(strict_types=1);

namespace App\Trading\Application\Job\ApplyLockInProfit;

use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Settings\Application\Helper\SettingsHelper;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitBySteps\LockInProfitByStepsStrategy;
use App\Trading\Application\Settings\LockInProfitSettings;
use App\Trading\Contract\LockInProfit\Enum\LockInProfitStrategy;
use App\Trading\Contract\LockInProfit\LockInProfitEntry;
use App\Trading\Contract\LockInProfit\LockInProfitHandlerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ApplyLockInProfitJobJobHandler
{
    public function __invoke(ApplyLockInProfitJob $job): void
    {
        if (!SettingsHelper::exact(LockInProfitSettings::Enabled)) {
            return;
        }

        $positions = $this->positionService->getPositionsWithLiquidation();

        foreach ($positions as $position) {
            if (SettingsHelper::exactForSymbolAndSideOrSymbol(LockInProfitSettings::Enabled, $position->symbol, $position->side) === false) {
                return;
            }

            $entry = new LockInProfitEntry(
                $position,
                LockInProfitStrategy::BySteps,
                LockInProfitByStepsStrategy::defaultThreeStepsLock()
            );

            $this->handler->handle($entry);
        }
    }

    public function __construct(
        private ByBitLinearPositionService $positionService,
        private LockInProfitHandlerInterface $handler,
    ) {
    }
}
