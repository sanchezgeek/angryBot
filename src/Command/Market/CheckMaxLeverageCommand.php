<?php

namespace App\Command\Market;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function json_encode;
use function sprintf;

#[AsCommand(name: 'leverage:check-max')]
class CheckMaxLeverageCommand extends AbstractCommand
{
    use SymbolAwareCommand;
    use PriceRangeAwareCommand;

    protected function configure(): void
    {
        $this->configureSymbolArgs();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->positionService->getAllPositions() as $symbolRaw => $symbolPositions) {
            $symbol = SymbolEnum::from($symbolRaw);
            $maxLeverage = $this->exchangeService->getInstrumentInfo($symbol)->maxLeverage;

            foreach ($symbolPositions as $position) {
                if ($position->leverage->value() < $maxLeverage) {
                    $this->io->info(sprintf('./bin/console p:leverage:change --symbol=%s %d', $symbolRaw, $maxLeverage));
                    break;
                }
            }
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly ByBitLinearPositionService $positionService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
