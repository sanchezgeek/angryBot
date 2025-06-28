<?php

namespace App\Command\Market;

use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\ByBit\Service\Market\ByBitLinearMarketService;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(name: 'leverage:check-max')]
class CheckMaxLeverageCommand extends AbstractCommand implements SymbolDependentCommand
{
    use SymbolAwareCommand;

    protected function configure(): void
    {
        $this->configureSymbolArgs();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->positionService->getAllPositions() as $symbolRaw => $symbolPositions) {
            $maxLeverage = $this->marketService->getInstrumentInfo($symbolRaw)->maxLeverage;

            foreach ($symbolPositions as $position) {
                if ($position->leverage->value() < $maxLeverage) {
                    $this->io->info(sprintf('./bin/console p:leverage:change --symbol=%s %d', $symbolRaw, $maxLeverage));
                    try {
                        $this->positionService->setLeverage($position->symbol, $maxLeverage, $maxLeverage);
                    } catch (Exception $e) {
                        OutputHelper::print($e->getMessage());
                    }

                    break;
                }
            }
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ByBitLinearMarketService $marketService,
        private readonly ByBitLinearPositionService $positionService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
