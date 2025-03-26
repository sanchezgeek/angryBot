<?php

declare(strict_types=1);

namespace App\Command\Hedge;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\CommandRunnerCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(name: 'p:hedge:open')]
class HedgePositionCommand extends AbstractCommand
{
    use SymbolAwareCommand;
    use PriceRangeAwareCommand;
    use CommandRunnerCommand;

    public const MAIN_POSITION_SIZE_PART_OPTION = 'part';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addOption(self::MAIN_POSITION_SIZE_PART_OPTION, 'p', InputOption::VALUE_OPTIONAL, 'Percent of main position size to hedge');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $this->getSymbol();

        $percentToHedge = $this->paramFetcher->requiredPercentOption(name: self::MAIN_POSITION_SIZE_PART_OPTION, asPercent: true);
        if (!($positions = $this->positionService->getPositions($symbol))) {
            throw new Exception(sprintf('No opened positions on %s', $symbol->value));
        }

        $hedge = $positions[0]->getHedge();
        $positionToHedge = $hedge?->mainPosition ?? $positions[0];

        $hedgedSize = $hedge?->supportPosition->size ?? 0;
        $hedgedPart = Percent::fromPart(FloatHelper::round($hedgedSize / $positionToHedge->size), false);
        if ($hedgedPart->value() > 0) {
            $this->io->info(sprintf('%s of %s already hedged', $hedgedPart, $positionToHedge->getCaption()));
        }

        $needToHedge = $percentToHedge->sub($hedgedPart);
        if ($needToHedge->value() <= 0) {
            return Command::FAILURE;
        }

        if (!$this->io->confirm(sprintf('You\'re about to hedge %s of %s', $needToHedge, $positionToHedge->getCaption()))) {
            return self::FAILURE;
        }

        $qtyToOpenOnSupportSide = $symbol->roundVolume($needToHedge->of($positionToHedge->size));
        $this->tradeService->marketBuy($symbol, $positionToHedge->side->getOpposite(), $qtyToOpenOnSupportSide);

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly OrderServiceInterface $tradeService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
