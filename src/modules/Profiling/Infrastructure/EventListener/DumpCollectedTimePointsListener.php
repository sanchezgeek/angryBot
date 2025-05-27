<?php

declare(strict_types=1);

namespace App\Profiling\Infrastructure\EventListener;

use App\Profiling\Application\Collector\ProfilingPointsStaticCollector;
use App\Profiling\Application\Settings\ProfilingSettings;
use App\Profiling\Application\Storage\ProfilingPointStorage;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final readonly class DumpCollectedTimePointsListener implements EventSubscriberInterface
{
    public function __construct(
        private AppSettingsProviderInterface $settingsProvider,
        private ProfilingPointStorage $timePointStorage
    ) {
    }

    private function dumpCollectedEvents(): void
    {
        if (!$this->settingsProvider->optional(SettingAccessor::exact(ProfilingSettings::ProfilingEnabled))) {
            return;
        }

        foreach (ProfilingPointsStaticCollector::releasePoints() as $point) {
            $this->timePointStorage->save($point);
        }
    }

    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->dumpCollectedEvents();
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->dumpCollectedEvents();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onWorkerMessageHandled',
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];
    }
}
