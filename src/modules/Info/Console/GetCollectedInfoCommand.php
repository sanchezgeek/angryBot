<?php

namespace App\Info\Console;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Position\ValueObject\Side;
use App\Info\Application\Service\DependencyInfoCollector;
use App\Info\Contract\DependencyInfoProviderInterface;
use App\Info\Contract\Dto\InfoAboutEnumDependency;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\Color;
use App\Output\Table\Formatter\ConsoleTableBuilder;
use App\Settings\Application\Helper\SettingsHelper;
use App\Trading\Application\Settings\LockInProfitSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(name: 'info:get')]
class GetCollectedInfoCommand extends AbstractCommand
{
    use PositionAwareCommand;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->services as $service) {
            $this->collector->addInfo($service->getDependencyInfo());
        }

        /** @var array<string, InfoAboutEnumDependency[]> $groupsByDependentOn */
        $groupsByDependentOn = $this->collector->getGroupedByDependentOn(InfoAboutEnumDependency::class);


        $rows = [];
        foreach ($groupsByDependentOn as $dependentOn => $items) {

            $rows[] = DataRow::separated([Cell::restColumnsMerged(sprintf('        dependendsOn: %s', $dependentOn))->addStyle(new CellStyle(fontColor: Color::GREEN))]);
            foreach ($items as $infoItem) {
                $rows[] = DataRow::separated([$infoItem->dependentTarget, $infoItem->stringInfo("\n")]);
            }
        }

        ConsoleTableBuilder::withOutput($this->output)
            ->withRows(...$rows)
            ->withHeader(['target', 'info'])
            ->build()
            ->setStyle('box')
            ->render();

        return Command::SUCCESS;
    }

    /**
     * @param iterable<DependencyInfoProviderInterface> $services
     */
    public function __construct(
        private readonly DependencyInfoCollector $collector,
        #[AutowireIterator('info.info_provider')]
        private iterable $services,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
