<?php

namespace App\Settings\UI\Symfony\Command\Settings;

use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Helper\OutputHelper;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\SeparatorRow;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Dto\Style\Enum\Color;
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
    use SymbolAwareCommand;

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $this->symbolIsSpecified() ? $this->getSymbol() : null;

        $rows = [];
        foreach ($this->settingsLocator->getRegisteredSettingsGroups() as $group) {
            $rows[] = DataRow::separated([
                Cell::restColumnsMerged(sprintf('%s', OutputHelper::shortClassName($group)))->addStyle(new CellStyle(fontColor: Color::YELLOW, align: CellAlign::CENTER))
            ]);
            $groupCases = $group::cases();
            foreach ($groupCases as $settKey => $setting) {
                $values = $this->settingsProvider->getAllSettingAssignedValuesCollection($setting);

                if (!$values->isSettingHasFallbackValue()) {
                    $rows[] = DataRow::default([$setting->getSettingKey(), Cell::align(CellAlign::CENTER, '---'), Cell::align(CellAlign::CENTER, '---')]);
                }

                foreach ($values as $assignedValue) {
                    $isFallbackValue = $assignedValue->isFallbackValue();

                    if ($symbol && !($isFallbackValue || $assignedValue->symbol === $symbol)) {
                        continue;
                    }

                    $fullKey = $assignedValue->fullKey;
                    $baseKey = $setting->getSettingKey();

                    $style = CellStyle::default();
                    if ($isFallbackValue) {
                        $settingKey = $baseKey;
                    } else {
                        $settingKey = str_pad(str_replace($baseKey, '', $fullKey), 30);
                        $style = CellStyle::right();
                    }

                    $rows[] = DataRow::default(
                        [
                            Cell::default($settingKey)->addStyle($style),
                            (string)$assignedValue,
                            Cell::default(
                                $assignedValue->info . ($assignedValue->isDefault() ? '   ' : '')
                            )->addStyle(new CellStyle(fontColor: $assignedValue->isDefault() ? Color::GRAY : Color::DEFAULT)),
                        ]
                    );
                }
                if ($settKey !== array_key_last($groupCases)) {
                    $rows[] = new SeparatorRow();
                }
            }
        }

        ConsoleTableBuilder::withOutput($this->output)
            ->withHeader(['name', 'value', 'comment'])
            ->withRows(...$rows)
            ->build()
            ->setStyle('box')
            ->render();

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
