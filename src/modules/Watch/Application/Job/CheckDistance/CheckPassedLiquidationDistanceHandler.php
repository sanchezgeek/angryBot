<?php

declare(strict_types=1);

namespace App\Watch\Application\Job\CheckDistance;

use App\Alarm\Application\Settings\AlarmSettings;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Notification\Application\Service\AppNotificationsService;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckPassedLiquidationDistanceHandler
{
    public function __construct(
        private AppSettingsProviderInterface $settings,
        private ByBitLinearPositionService $positionService,
        private AppNotificationsService $appNotificationsService
    ) {
    }

    public function __invoke(CheckPassedLiquidationDistance $message): void
    {
        $positions = $this->positionService->getPositionsWithLiquidation();
        $lastMarkPrices = $this->positionService->getLastMarkPrices();

        foreach ($positions as $position) {
            $symbol = $position->symbol;
            $markPrice = $lastMarkPrices[$symbol->name()];

            $initialLiquidationDistance = $position->liquidationDistance();
            $distanceBetweenLiquidationAndTicker = $position->liquidationPrice()->deltaWith($markPrice);

            $initialLiquidationDistancePercentOfEntry = Percent::fromPart($initialLiquidationDistance / $position->entryPrice, false);
            $distanceBetweenLiquidationAndTickerPercentOfEntry = Percent::fromPart($distanceBetweenLiquidationAndTicker / $position->entryPrice, false);
            if ($distanceBetweenLiquidationAndTickerPercentOfEntry->value() < $initialLiquidationDistancePercentOfEntry->value()) {
                $allowedPercent = $this->settings->required(SettingAccessor::withAlternativesAllowed(AlarmSettings::PassedPart_Of_LiquidationDistance, $symbol, $position->side));
                $passedLiquidationDistancePercent = Percent::fromPart(($initialLiquidationDistancePercentOfEntry->value() - $distanceBetweenLiquidationAndTickerPercentOfEntry->value()) / $initialLiquidationDistancePercentOfEntry->value());

                if ($passedLiquidationDistancePercent->value() > $allowedPercent) {
                    $this->appNotificationsService->notify(sprintf('%s: passed distance > %s', $symbol->name(), $allowedPercent));
                }
            }
        }
    }
}
