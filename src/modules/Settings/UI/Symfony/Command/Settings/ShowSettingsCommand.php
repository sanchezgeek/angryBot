<?php

namespace App\Settings\UI\Symfony\Command\Settings;

use App\Command\AbstractCommand;
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->settingsLocator->getRegisteredSettingsGroups() as $group) {
            $rows[] = DataRow::separated([
                Cell::restColumnsMerged(sprintf('~ ~ ~ %s ~ ~ ~', OutputHelper::shortClassName($group)))->addStyle(new CellStyle(fontColor: Color::YELLOW, align: CellAlign::CENTER))
            ]);
            $groupCases = $group::cases();
            foreach ($groupCases as $settKey => $setting) {
                $values = $this->settingsProvider->getAllSettingAssignedValuesCollection($setting);

                if (!$values->isSettingHasFallbackValue()) {
                    $rows[] = DataRow::separated([sprintf('%s (without fallback)', $setting->getSettingKey())]);
                }

                foreach ($values as $assignedValue) {

                    $fullKey = $assignedValue->fullKey;
                    $baseKey = $setting->getSettingKey();

                    $style = CellStyle::default();
                    if ($assignedValue->isFallbackValue()) {
                        $settingKey = sprintf('%s (fallback)', $baseKey);
                    } else {
                        $settingKey = str_pad(str_replace($baseKey, '', $fullKey), 30);
                        $style = CellStyle::right();
                    }

                    if (!$assignedValue->isDefault()) {
                        $style->align = CellAlign::RIGHT;
                    }

                    $rows[] = DataRow::default(
                        [
                            Cell::default($settingKey)->addStyle($style),
                            (string)$assignedValue,
                            Cell::default(
                                $assignedValue->info . ($assignedValue->isDefault() ? '   ' : '')
                            )->setAlign($assignedValue->isDefault() ? CellAlign::LEFT : CellAlign::RIGHT),
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
