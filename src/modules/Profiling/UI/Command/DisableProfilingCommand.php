<?php

namespace App\Profiling\UI\Command;

use App\Command\AbstractCommand;
use App\Profiling\Application\Settings\ProfilingSettings;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingAccessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'profiling:disable')]
class DisableProfilingCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->settingsService->set(SettingAccessor::exact(ProfilingSettings::ProfilingEnabled), false);

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly AppSettingsService $settingsService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
