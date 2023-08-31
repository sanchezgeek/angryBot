<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Pnl;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\PositionStopRangesCollection;
use App\Domain\Stop\StopsCollection;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_numeric;
use function sprintf;

#[AsCommand(name: 'sl:info')]
class StopInfoCommand extends Command
{
    private const DEFAULT_PNL_STEP = 20;

    private InputInterface $input;

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
            ->addOption('pnlStep', '-p', InputOption::VALUE_REQUIRED, 'Pnl step (%)', self::DEFAULT_PNL_STEP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $this->input = $input;

        $position = $this->getPosition();
        $pnlStep = $this->getIntParam($input->getOption('pnlStep'), 'pnlStep');

        $stops = $this->stopRepository->findActive(
            side: $position->side,
            qbModifier: function (QueryBuilder $qb) use ($position) {
                $priceField = $qb->getRootAliases()[0] . '.price';

                $qb->orderBy($priceField, $position->side->isShort() ? 'DESC' : 'ASC');
            }
        );

        $rangesCollection = new PositionStopRangesCollection($position, new StopsCollection(...$stops), $pnlStep);

        $totalUsdPnL = $totalVolume = 0;
        foreach ($rangesCollection as $rangeDesc => $stops) {
            $usdPnL = $stops->totalUsdPnL($position);

            $io->note(
                \sprintf(
                    '[%s] | %.1f%% (%s)',
                    $rangeDesc,
                    $stops->positionSizePart($position),
                    new Pnl($usdPnL, 'USDT'),
                ),
            );

            $totalUsdPnL += $usdPnL;
            $totalVolume += $stops->totalVolume();
        }

        $io->note([
            \sprintf('total PNL: %s', new Pnl($totalUsdPnL)),
            \sprintf('volume stopped: %.2f%%', ($totalVolume / $position->size) * 100),
        ]);

        return Command::SUCCESS;
    }

    private function getIntParam(string $value, string $name): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(
                sprintf('Invalid \'%s\' INT param provided ("%s" given).', $name, $value)
            );
        }

        return (int)$value;
    }

    private function getPosition(): Position
    {
        if (!$positionSide = Side::tryFrom($this->input->getArgument('position_side'))) {
            throw new InvalidArgumentException(
                sprintf('Invalid $step provided (%s)', $this->input->getArgument('position_side')),
            );
        }

        if (!$position = $this->positionService->getPosition(Symbol::BTCUSDT, $positionSide)) {
            throw new RuntimeException(sprintf('Position on %s side not found', $positionSide->title()));
        }

        return $position;
    }

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly StopRepository $stopRepository,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
