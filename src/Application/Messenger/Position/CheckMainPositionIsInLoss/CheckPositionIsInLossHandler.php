<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckMainPositionIsInLoss;

use App\Alarm\Application\Settings\AlarmSettings;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final readonly class CheckPositionIsInLossHandler
{
    public function __invoke(CheckPositionIsInLoss $message): void
    {
        /** @var $positions array<Position[]> */
        $positions = $this->positionService->getAllPositions();
        $lastMarkPrices = $this->positionService->getLastMarkPrices();

        foreach ($positions as $symbolPositions) {
            $mainPosition = ($first = $symbolPositions[array_key_first($symbolPositions)])->getHedge()?->mainPosition ?? $first;
            $symbol = $mainPosition->symbol;

            if (!$this->positionInLossAlertThrottlingLimiter->create($symbol->value)->consume()->isAccepted()) {
                continue;
            }

            if (!$this->settings->optional(SettingAccessor::withAlternativesAllowed(AlarmSettings::AlarmOnLossEnabled, $symbol))) {
                continue;
            }

            if ($mainPosition->isPositionInLoss($lastMarkPrices[$symbol->value])) {
                $this->appErrorLogger->error(sprintf('%s is in loss', $mainPosition->getCaption()));
            }
        }
    }

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private PositionServiceInterface $positionService,
        private LoggerInterface $appErrorLogger,
        private RateLimiterFactory $positionInLossAlertThrottlingLimiter,
    ) {
    }
}
