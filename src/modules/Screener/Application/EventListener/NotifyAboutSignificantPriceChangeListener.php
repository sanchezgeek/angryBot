<?php

declare(strict_types=1);

namespace App\Screener\Application\EventListener;

use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Screener\Application\Event\SignificantPriceChangeFoundEvent;
use App\Screener\Application\Settings\ScreenerNotificationsSettings;
use App\Settings\Application\Helper\SettingsHelper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsEventListener]
final readonly class NotifyAboutSignificantPriceChangeListener
{
    private RateLimiterFactory $limiter;

    public function __invoke(SignificantPriceChangeFoundEvent $event): void
    {
        $info = $event->info;
        $searchOnDaysDelta = $event->foundWhileSearchOnDaysDelta;
        $symbol = $info->info->symbol;

        // либо это должно быть в поисковике
        // @todo | priceChange | save prev percent and notify again if new percent >= prev
        // короче тут надо плюс к троттлингу кешировать ценовой уровень, за которым оповещение сработает ещё раз
        if (!$this->limiter->create(sprintf('%s_daysDelta_%d', $symbol->name(), $searchOnDaysDelta))->consume()->isAccepted()) {
            return;
        }

        $priceChangePercent = $info->info->getPriceChangePercent()->setOutputFloatPrecision(2);
        $message = sprintf(
            'days=%.2f [! %s !] %s [days=%.2f from %s].price=%s vs curr.price = %s: Δ = %s (%s > %s) %s',
            $info->info->partOfDayPassed,
            $priceChangePercent,
            $symbol->name(),
            $info->info->partOfDayPassed,
            $info->info->fromDate->format('m-d'),
            $info->info->fromPrice,
            $info->info->toPrice,
            $info->info->priceDelta(),
            $priceChangePercent,
            $info->pricePercentChangeConsideredAsSignificant->setOutputFloatPrecision(2), // @todo | priceChange | +/-
            $symbol->name(),
        );

        match (SettingsHelper::exactlyRoot(ScreenerNotificationsSettings::SignificantPriceChange_Notifications_Enabled)) {
            true => $this->notifications->notify($message),
            default => $this->notifications->muted($message),
        };
    }

    public function __construct(
        private AppNotificationsServiceInterface $notifications,
        private AttemptLimitCheckerProviderInterface $attemptLimitCheckerProvider,
    ) {
        $this->limiter = $this->attemptLimitCheckerProvider->getLimiterFactory(3600);
    }
}
