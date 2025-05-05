<?php

namespace App\Settings\UI\Symfony\Command;

use App\Command\AbstractCommand;
use App\Helper\OutputHelper;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Formatter\ConsoleTableBuilder;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingsLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:show')]
class ShowSettingsCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->settingsLocator->getRegisteredSettingsGroups() as $group) {
            $rows[] = DataRow::separated([Cell::restColumnsMerged(OutputHelper::shortClassName($group))->setAlign(CellAlign::CENTER)]);
            foreach ($group::cases() as $setting) {
                $values = $this->settingsProvider->getAllSettingAssignedValues($setting);

                foreach ($values as $assignedValue) {
                    $value = $assignedValue->value;
                    $value = match (true) {
                        $assignedValue->isDisabled() => 'disabled',
                        is_object($value) && method_exists($value, '__toString') => (string) $value,
                        default => var_export($value, true)
                    };
                    $rows[] = DataRow::default([$assignedValue->fullKey, $value, $assignedValue->info]);
                }
            }
        }

        ConsoleTableBuilder::withOutput($this->output)
            ->withHeader(['name', 'value', 'comment'])
            ->withRows(...$rows)
            ->build()
            ->setStyle('box')
            ->render();

        // func tests
//        var_dump($this->settingsProvider->get(SettingAccessor::bySide(SafePriceDistanceSettings::SafePriceDistance_Percent, Symbol::BTCUSDT, Side::Sell), false));

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly SettingsLocator $settingsLocator,
        private readonly AppSettingsService $settingsProvider,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
