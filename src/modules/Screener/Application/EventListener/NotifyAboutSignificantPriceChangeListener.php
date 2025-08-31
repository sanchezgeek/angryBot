<?php

declare(strict_types=1);

namespace App\Screener\Application\EventListener;

use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Domain\Position\ValueObject\Side;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Screener\Application\Event\SignificantPriceChangeFoundEvent;
use App\Screener\Application\Settings\ScreenerNotificationsSettings;
use App\Settings\Application\Helper\SettingsHelper;
use App\TechnicalAnalysis\Application\Helper\TA;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsEventListener]
final readonly class NotifyAboutSignificantPriceChangeListener
{
    private const int THRESHOLD = 20;

    private RateLimiterFactory $limiter;

    public function __invoke(SignificantPriceChangeFoundEvent $event): void
    {
        $info = $event->info;
        $priceChangeInfo = $info->info;
        $foundOnDaysDelta = $event->foundWhileSearchOnDaysDelta;
        $symbol = $priceChangeInfo->symbol;

        $positionSide = $event->positionSideToPositionLoss();

        if ($positionSide === Side::Sell) {
            $ticker = $this->exchangeService->ticker($symbol);
            $currentPricePartOfAth = TA::currentPricePartOfAth($symbol, $ticker->markPrice);
            if ($currentPricePartOfAth->value() < self::THRESHOLD) {
                self::output(sprintf('skip notify about %s %s ($currentPricePartOfAth (%s) < %s)', $symbol->name(), $positionSide->title(), $currentPricePartOfAth, self::THRESHOLD));
                return;
            }
        }

        // либо это должно быть в поисковике
        // @todo | priceChange | save prev percent and notify again if new percent >= prev
        // короче тут надо плюс к троттлингу кешировать ценовой уровень, за которым оповещение сработает ещё раз
        if (!$this->limiter->create(sprintf('%s_%s_daysDelta_%d', $symbol->name(), $positionSide->value, $foundOnDaysDelta))->consume()->isAccepted()) {
            return;
        }

        if ($openedPosition = $this->positionService->getPosition($symbol, $positionSide)) {
            return;
        }

        $priceChangePercent = $priceChangeInfo->getPriceChangePercent()->setOutputFloatPrecision(2);
        $message = sprintf(
            'days=%.2f [! %s !] %s [days=%.2f from %s].price=%s vs curr.price = %s: Δ = %s (%s > %s) %s',
            $priceChangeInfo->partOfDayPassed,
            $priceChangePercent,
            $symbol->name(),
            $priceChangeInfo->partOfDayPassed,
            $priceChangeInfo->fromDate->format('m-d'),
            $priceChangeInfo->fromPrice,
            $priceChangeInfo->toPrice,
            $priceChangeInfo->priceDelta(),
            $priceChangePercent,
            $info->pricePercentChangeConsideredAsSignificant->setOutputFloatPrecision(2), // @todo | priceChange | +/-
            $symbol->name(),
        );

        match (SettingsHelper::exact(ScreenerNotificationsSettings::SignificantPriceChange_Notifications_Enabled)) {
            true => $this->notifications->info($message),
            default => $this->notifications->muted($message),
        };
    }

    private static function output(string $message): void
    {
        OutputHelper::warning(
            sprintf('%s: %s', OutputHelper::shortClassName(self::class), $message)
        );
    }

    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private ByBitLinearPositionService $positionService,
        private AppNotificationsServiceInterface $notifications,
        private AttemptLimitCheckerProviderInterface $attemptLimitCheckerProvider,
    ) {
        $this->limiter = $this->attemptLimitCheckerProvider->getLimiterFactory(3600);
    }
}
