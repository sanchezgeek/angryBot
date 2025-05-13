<?php

namespace App\Settings\UI\Symfony\Command\Settings;

use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Service\SettingsLocator;
use App\Settings\Application\Storage\AssignedSettingValueFactory;
use App\Settings\Application\Storage\SettingsStorageInterface;
use App\Settings\Domain\SettingValueValidator;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'settings:edit')]
class EditSettingCommand extends AbstractCommand
{
    use SymbolAwareCommand;

    private const RESET_OPTION = 'reset-all-values';

    private const SETTING_KEY_ARG = 'key';
    private const SETTING_VALUE_OPTION = 'value';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->addArgument(self::SETTING_KEY_ARG, InputArgument::OPTIONAL, 'Provide key for fast access')
            ->addOption(self::RESET_OPTION, null, InputOption::VALUE_NEGATABLE)
            ->addOption(self::SETTING_VALUE_OPTION, null, InputOption::VALUE_REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $reset = $this->paramFetcher->getBoolOption(self::RESET_OPTION);
        $settingKey = $this->paramFetcher->getStringArgument(self::SETTING_KEY_ARG);
        $specifiedValue = $this->paramFetcher->getStringOption(self::SETTING_VALUE_OPTION, false);

        if (!$settingKey) {
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
        } else {
            if (!$selectedSetting = $this->settingsLocator->tryGetBySettingBaseKey($settingKey)) {
                throw new InvalidArgumentException(sprintf('Cannot find settings group by provided %s', $settingKey));
            }
        }

        if (!$reset) {
            if (!($this->symbolIsSpecified() && $symbol = $this->getSymbol())) {
                $symbol = $io->ask("Symbol (default = `null`):"); $symbol = $symbol !== null ? Symbol::fromShortName(strtoupper($symbol)) : null;
            }
            $side = $io->ask("Side (default = `null`):"); $side = $side !== null ? Side::from($side) : null;
        } else {
            $symbol = null;
            $side = null;
        }

        if ($reset) {
            $this->settingsService->resetSetting($selectedSetting);

            return Command::SUCCESS;
        }

        $settingAccessor = SettingAccessor::exact($selectedSetting, $symbol, $side);
        $storedSettingValue = $this->storage->get($settingAccessor);
        $currentValue = $storedSettingValue ? AssignedSettingValueFactory::byAccessorAndValue($settingAccessor, $storedSettingValue->value) : null;

        $action = $specifiedValue ? 'e' : $io->ask("Action: e - set, d - disable (disables default value), r - remove");
        if ($action === 'r') {
            if (!$storedSettingValue) {
                $io->info('Have not stored value. Skip');
            } else {
                $this->settingsService->unset($settingAccessor);
            }

            return Command::SUCCESS;
        } elseif ($action === 'd') {
            $this->settingsService->disable($settingAccessor);
        } elseif ($action === 'e') {
            assert($value = $specifiedValue ?? $io->ask(sprintf('%sValue:', $currentValue ? sprintf('Current value = %s. ', $currentValue) : '')), new InvalidArgumentException('Value must be specified'));

            $value = match ($value) {'false' => false, 'true' => true, default => $value};
            if (!SettingValueValidator::validate($selectedSetting, $value)) {
                $value = json_encode($value);
                throw new InvalidArgumentException(sprintf('Invalid value "%s" for setting "%s"', $value, $settingKey));
            }

            $newValue = AssignedSettingValueFactory::byAccessorAndValue($settingAccessor, $value);
            $confirmMsg = $storedSettingValue
                ? sprintf('Existed setting value for "%s" = %s. Override to "%s"?', $settingAccessor, $currentValue, $newValue)
                : sprintf('You want to store %s with value "%s". Ok?', $settingAccessor, $newValue);

            if (!$io->confirm($confirmMsg)) {
                return Command::SUCCESS;
            }

            $this->settingsService->set($settingAccessor, $value);
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
