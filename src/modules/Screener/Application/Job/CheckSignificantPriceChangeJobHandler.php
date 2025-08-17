<?php

declare(strict_types=1);

namespace App\Screener\Application\Job;

use App\EventBus\EventBus;
use App\Screener\Application\Contract\Query\FindSignificantPriceChange;
use App\Screener\Application\Contract\Query\FindSignificantPriceChangeHandlerInterface;
use App\Screener\Application\Event\SignificantPriceChangeFoundEvent;
use App\Screener\Application\Settings\ScreenerEnabledHandlersSettings;
use App\Settings\Application\Helper\SettingsHelper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckSignificantPriceChangeJobHandler
{
    public function __invoke(CheckSignificantPriceChangeJob $job): void
    {
        // move to some interface
        if (SettingsHelper::exactlyRoot(ScreenerEnabledHandlersSettings::SignificantPriceChange_Screener_Enabled) !== true) {
            return;
        }

        $foundItems = $this->finder->handle(new FindSignificantPriceChange($job->settleCoin, $job->daysDelta));
        foreach ($foundItems as $item) {
            $this->events->dispatch(
                new SignificantPriceChangeFoundEvent($item, $job->daysDelta)
            );
        }
    }

    public function __construct(
        private FindSignificantPriceChangeHandlerInterface $finder,
        private EventBus $events,
    ) {
    }
}
