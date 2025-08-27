<?php

declare(strict_types=1);

namespace App\Watch\Application\Job\CheckPassedLiquidationDistance;

use App\Alarm\Application\Settings\AlarmSettings;
use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersFactoryInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Command\Position\OpenedPositions\Cache\OpenedPositionsCache;
use App\Domain\Price\SymbolPrice;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Notification\Application\Service\AppNotificationsService;
use App\Settings\Application\Helper\SettingsHelper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final readonly class CheckPassedLiquidationDistanceHandler
{
    private const int MIN_THRESHOLD_WITH_ALLOWED = 2;

    private RateLimiterFactory $limiterFactory;

    public function __construct(
        private ByBitLinearPositionService $positionService,
        private AppNotificationsService $appNotificationsService,
        private LiquidationDynamicParametersFactoryInterface $liquidationDynamicParametersFactory,
        AttemptLimitCheckerProviderInterface $attemptLimitCheckerProvider,
        private OpenedPositionsCache $openedPositionsCache,
    ) {
        $this->limiterFactory = $attemptLimitCheckerProvider->getLimiterFactory(180);
    }

    public function __invoke(CheckPassedLiquidationDistance $message): void
    {
        $positions = $this->positionService->getPositionsWithLiquidation();
        $lastMarkPrices = $this->positionService->getLastMarkPrices();

        $symbolsToWatch = $this->openedPositionsCache->getSymbolsToWatch();

        foreach ($positions as $position) {
            $symbol = $position->symbol;

            if (!in_array($symbol->name(), $symbolsToWatch, true)) {
                continue;
            }

            if (!$this->limiterFactory->create($symbol->name())->consume()->isAccepted()) {
                continue;
            }

            $markPrice = $lastMarkPrices[$symbol->name()];

            // @todo | CheckPassedLiquidationDistanceHandler | handle case when liquidation placed before entry
            if ($position->isLiquidationPlacedBeforeEntry()) {
                continue;
            }

            $initialLiquidationDistance = $position->liquidationDistance();
            $distanceBetweenLiquidationAndTicker = $position->liquidationPrice()->deltaWith($markPrice);

            $initialLiquidationDistancePercentOfEntry = Percent::fromPart($initialLiquidationDistance / $position->entryPrice, false);
            $distanceBetweenLiquidationAndTickerPercentOfEntry = Percent::fromPart($distanceBetweenLiquidationAndTicker / $position->entryPrice, false);
            if ($distanceBetweenLiquidationAndTickerPercentOfEntry->value() < $initialLiquidationDistancePercentOfEntry->value()) {
                [$allowed, $alarmPercent] = $this->getAlarmPassedDistance($position, $markPrice);
                $passedLiquidationDistancePercent = Percent::fromPart(
                    ($initialLiquidationDistancePercentOfEntry->value() - $distanceBetweenLiquidationAndTickerPercentOfEntry->value()) / $initialLiquidationDistancePercentOfEntry->value()
                );

                if ($passedLiquidationDistancePercent->value() > $alarmPercent->value()) {
                    $this->appNotificationsService->notify(
                        sprintf(
                            '%s: passed distance %s > %s (%s allowed)',
                            $symbol->name(),
                            $passedLiquidationDistancePercent->setOutputFloatPrecision(2),
                            $alarmPercent->setOutputFloatPrecision(2),
                            $allowed->setOutputFloatPrecision(2)
                        ),
                        type: 'warning'
                    );
                }
            }
        }
    }

    /**
     * @return Percent[]
     */
    private function getAlarmPassedDistance(Position $position, SymbolPrice $currentPrice): array
    {
        $symbol = $position->symbol;
        $side = $position->side;

        if ($override = SettingsHelper::getForSideOrSymbol(AlarmSettings::PassedPart_Of_LiquidationDistance, $symbol, $side)) {
            return $override;
        }

        $liquidationParameters = $this->liquidationDynamicParametersFactory->fakeWithoutHandledMessage($position, Ticker::fakeForPrice($symbol, $currentPrice));
        $percentOfLiquidationDistanceToAddStop = $liquidationParameters->percentOfLiquidationDistanceToAddStop()->value();

        $allowed = 100 - $percentOfLiquidationDistanceToAddStop;
        $threshold = SettingsHelper::getForSideOrSymbol(AlarmSettings::PassedPart_Of_LiquidationDistance_Threshold_From_Allowed, $symbol, $side);

        if ($threshold === null) {
            $threshold = $allowed / 10;
            $threshold = max($threshold, self::MIN_THRESHOLD_WITH_ALLOWED);
        }

        $alarm = $allowed - $threshold;

        return [Percent::notStrict($allowed), Percent::notStrict($alarm)];
    }
}
