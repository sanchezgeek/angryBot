<?php

namespace App\Settings\UI\Symfony\Command\Settings;

use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Helper\OutputHelper;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\SeparatorRow;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Dto\Style\Enum\Color;
use App\Output\Table\Formatter\ConsoleTableBuilder;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Service\SettingsLocator;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:show')]
class ShowSettingsCommand extends AbstractCommand implements SymbolDependentCommand
{
    use SymbolAwareCommand;

    private const string ONLY_OVERRIDES_OPTION = 'only-overrides';
    private const string CATEGORY_OPTION = 'category';
    private const string EXACT_CATEGORY_OPTION = 'exact-category';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addOption(self::ONLY_OVERRIDES_OPTION, 'o', InputOption::VALUE_NEGATABLE, 'Show only overridden values')
            ->addOption(self::CATEGORY_OPTION, 'c', InputOption::VALUE_OPTIONAL, 'Category')
            ->addOption(self::EXACT_CATEGORY_OPTION, 's', InputOption::VALUE_OPTIONAL, 'Exact category')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $category = $this->paramFetcher->getStringOption(self::CATEGORY_OPTION, false);
        $exactCategory = $this->paramFetcher->getStringOption(self::EXACT_CATEGORY_OPTION, false);

        $symbol = $this->symbolIsSpecified() ? $this->getSymbol() : null;
        $onlyOverrides = $this->paramFetcher->getBoolOption(self::ONLY_OVERRIDES_OPTION);

        $rows = [];
        foreach ($this->settingsLocator->getRegisteredSettingsGroups() as $group) {
            if ($exactCategory && $group::category() !== $exactCategory) {
                continue;
            } elseif ($category && !str_contains($group::category(), $category)) {
                continue;
            }

            $groupValuesRows = [];
            foreach ($groupCases = $group::cases() as $settKey => $setting) {
                $values = $this->settingsProvider->getAllSettingAssignedValuesCollection($setting);

                if ($symbol) {
                    $values = $values->filterByAccessor(SettingAccessor::withAlternativesAllowed($setting, $symbol));
                }

                // from initially fetched collection
                $settingFallbackValue = $values->getFallbackValueIfPresented();

                $valuesToForeach = $onlyOverrides ? array_filter($values->getItems(), static fn(AssignedSettingValue $value) => !$value->isDefault()) : $values;
                if (!$valuesToForeach) {
                    continue;
                }

                if (!$settingFallbackValue) {
                    $groupValuesRows[] = DataRow::default([$setting->getSettingKey(), Cell::align(CellAlign::CENTER, '---'), Cell::align(CellAlign::CENTER, '---')]);
                }

                // add removed fallback (default value must be shown)
                if ($settingFallbackValue && $onlyOverrides && reset($valuesToForeach) != $settingFallbackValue) {
                    $valuesToForeach = array_merge([$settingFallbackValue], $valuesToForeach);
                }

                $printedSettingRows = [];
                foreach ($valuesToForeach as $assignedValue) {
                    $isFallbackValue = $assignedValue->isFallbackValue();

                    $fullKey = $assignedValue->fullKey;
                    $baseKey = $setting->getSettingKey();

                    $style = CellStyle::default();
                    if ($isFallbackValue) {
                        $settingKey = $baseKey;
                    } else {
                        $settingKey = str_pad(str_replace($baseKey, '', $fullKey), 30);
                        $style = CellStyle::right();
                    }

                    $printedSettingRows[] = DataRow::default(
                        [
                            Cell::default($settingKey)->addStyle($style),
                            (string)$assignedValue,
                            Cell::default(
                                $assignedValue->info . ($assignedValue->isDefault() ? '   ' : '')
                            )->addStyle(new CellStyle(fontColor: $assignedValue->isDefault() ? Color::GRAY : Color::DEFAULT)),
                        ]
                    );
                }

                if ($printedSettingRows && $settKey !== array_key_last($groupCases)) {
                    $printedSettingRows[] = new SeparatorRow();
                }

                $groupValuesRows = array_merge($groupValuesRows, $printedSettingRows);
            }

            if ($groupValuesRows) {
                $groupHeaderCell = Cell::restColumnsMerged(sprintf('%s', OutputHelper::shortClassName($group)))->addStyle(new CellStyle(fontColor: Color::YELLOW, align: CellAlign::CENTER));
                $groupHeaderRows = [
                    (!$rows || get_class(end($rows)) !== SeparatorRow::class) ? DataRow::separated([$groupHeaderCell]) : DataRow::default([$groupHeaderCell])
                ];

                $rows = array_merge($rows, $groupHeaderRows, $groupValuesRows);
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
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
