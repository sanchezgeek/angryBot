<?php

namespace App\Settings\UI\Symfony\Command\Settings;

use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Service\SettingsLocator;
use App\Settings\Application\Storage\SettingsStorageInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'settings:set')]
class SetSettingCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $settingsGroups = $this->settingsLocator->getRegisteredSettingsGroups();
        $groupsAsk = array_map(static fn (string $className, int $key) => sprintf('%d: %s', $key, $className) , $settingsGroups, array_keys($settingsGroups));
        $group = $io->ask(sprintf("Select group:\n\n%s", implode("\n", $groupsAsk)));
        if (!$selectedGroup = $settingsGroups[$group] ?? null) {
            throw new InvalidArgumentException('Not found');
        }

        $settings = $selectedGroup::cases();
        $settingsAsk = array_map(static fn (AppSettingInterface $setting, int $key) => sprintf('%d: %s', $key, $setting->getSettingKey()) , $settings, array_keys($settings));
        $settingKey = $io->ask(sprintf("Select setting:\n\n%s", implode("\n", $settingsAsk)));
        if (!$selectedSetting = $settings[$settingKey] ?? null) {
            throw new InvalidArgumentException('Not found');
        }

        $symbol = $io->ask("Symbol:"); $symbol = $symbol !== null ? Symbol::fromShortName(strtoupper($symbol)) : null;
        $side = $io->ask("Side:"); $side = $side !== null ? Side::from($side) : null;

        $settingAccessor = new SettingAccessor($selectedSetting, $symbol, $side);
        $settingValue = $this->storage->get($settingAccessor);

        $action = $io->ask("Action: e - set, d - disable (disables default value), r - remove");

        if ($action === 'r') {
            if (!$settingValue) {
                $io->info('Have not stored value. Skip');
            } else {
                $this->storage->remove($settingAccessor);
            }

            return Command::SUCCESS;
        } elseif ($action === 'd') {
            $this->settingsService->disable($settingAccessor);
        } elseif ($action === 'e') {
            assert($value = $io->ask("Value:"), new InvalidArgumentException('Value must be specified'));
            $value = match ($value) {
                'false' => false,
                'true' => true,
                default => $value
            };

            if ($settingValue && !$io->confirm(sprintf('Existed setting value = %s. Override?', $settingValue->value))) {
                return Command::SUCCESS;
            }

            $this->storage->store($settingAccessor, $value);
        } else {
            throw new InvalidArgumentException(sprintf('Unrecognized action "%s"', $action));
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly SettingsLocator $settingsLocator,
        private readonly AppSettingsService $settingsService,
        private readonly SettingsStorageInterface $storage,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
