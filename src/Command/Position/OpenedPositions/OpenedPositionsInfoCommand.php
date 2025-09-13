<?php

namespace App\Command\Position\OpenedPositions;

use App\Command\AbstractCommand;
use App\Command\Helper\ConsoleTableHelper as CTH;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:opened:symbols')]
class OpenedPositionsInfoCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;

    private array $positions;

    /** @var float[] */
    private array $ims;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CTH::registerColors($output);

        $this->positions = $this->positionService->getAllPositions();
        $this->initializeIms();
        $symbols = $this->getOpenedPositionsSymbols();

        foreach ($symbols as $symbol) {
            echo "$symbol\n";
        }

        return Command::SUCCESS;
    }

    private function getOpenedPositionsSymbols(): array
    {
        $symbolsRaw = [];
        foreach ($this->positions as $symbolRaw => $symbolPositions) {
            $symbolsRaw[] = $symbolRaw;
        }

        $symbolsInitialMarginMap = $this->getSymbolsInitialMarginMap();
        asort($symbolsInitialMarginMap);
        $symbolsInitialMarginMap = array_values(array_keys($symbolsInitialMarginMap));

        return array_intersect($symbolsInitialMarginMap, $symbolsRaw);
    }

    public function getSymbolsInitialMarginMap(): array
    {
        return array_map(static fn(float $im) => (string)$im, $this->ims);
    }

    public function initializeIms(): void
    {
        foreach ($this->positions as $symbolRaw => $positions) {
            $symbolIm = 0;
            foreach ($positions as $position) {
                $k = $position->leverage->value() / 100;
                $symbolIm += $position->initialMargin->value() * $k;
            }
            $this->ims[$symbolRaw] = $symbolIm;
        }
    }

    public function __construct(
        private readonly ByBitLinearPositionService $positionService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
