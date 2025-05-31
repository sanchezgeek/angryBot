<?php

namespace App\Settings\UI\Symfony\Command\Settings;

use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Settings\Application\Service\SettingsCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:cache:reset')]
class ResetSettingsCacheCommand extends AbstractCommand
{
    use SymbolAwareCommand;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->settingsCache->clear();

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly SettingsCache $settingsCache,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
