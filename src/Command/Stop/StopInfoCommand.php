<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Pnl;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\PositionStopRangesCollection;
use App\Domain\Stop\StopsCollection;
use App\Helper\Json;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;
use function is_bool;
use function sprintf;

#[AsCommand(name: 'sl:info')]
class StopInfoCommand extends Command
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;

    private const DEFAULT_PNL_STEP = 20;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption('pnlStep', '-p', InputOption::VALUE_REQUIRED, 'Pnl step (%)', (string)self::DEFAULT_PNL_STEP)
            ->addOption('aggregateWith', null, InputOption::VALUE_REQUIRED, 'Additional stops aggregate callback')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $this->withInput($input);

        $position = $this->getPosition();
        $pnlStep = $this->paramFetcher->getIntOption('pnlStep');
        $aggregateWith = $this->paramFetcher->getStringOption('aggregateWith', false);

        $stops = $this->stopRepository->findActive(
            side: $position->side,
            qbModifier: function (QueryBuilder $qb) use ($position) {
                $priceField = $qb->getRootAliases()[0] . '.price';

                $qb->orderBy($priceField, $position->side->isShort() ? 'DESC' : 'ASC');
            }
        );

        if (!$stops) {
            $io->info('Stops not found!'); return Command::SUCCESS;
        }

        $rangesCollection = new PositionStopRangesCollection($position, new StopsCollection(...$stops), $pnlStep);
        $totalUsdPnL = $totalVolume = 0;
        foreach ($rangesCollection as $rangeDesc => $stops) {
            if (!$stops->totalCount()) {
                continue;
            }

            $usdPnL = $stops->totalUsdPnL($position);

            $io->note(
                \sprintf(
                    '[%s] | %.1f%% (%s)',
                    $rangeDesc,
                    $stops->positionSizePart($position),
                    new Pnl($usdPnL, 'USDT'),
                ),
            );

            if ($aggregateWith) {
                $aggregateResult = [];
                foreach ($stops as $stop) {
                    eval('$callbackValue = $stop->' . $aggregateWith . ';');
                    $key = is_bool($callbackValue) ? ($callbackValue ? 'true' : 'false') : (string)$callbackValue;
                    $aggregateResult[$key] = $aggregateResult[$key] ?? new StopsCollection();
                    $aggregateResult[$key]->add($stop);
                }

                $aggregateInfo = [];
                foreach ($aggregateResult as $value => $aggregatedStops) {
                    $rangePart = $aggregatedStops->totalCount() / $stops->totalCount() * 100;
                    $aggregateInfo[] = Json::encode([
                        $aggregateWith . ' = ' . $value => [
                            'qnt' => $aggregatedStops->totalCount(),
                            'part' => sprintf('%s%%', PriceHelper::round($rangePart, 1))
                        ],
                    ]);
                }
                $io->info(implode("\n", $aggregateInfo));
            }

            $totalUsdPnL += $usdPnL;
            $totalVolume += $stops->totalVolume();
        }

        $io->note([
            \sprintf('total PNL: %s', new Pnl($totalUsdPnL)),
            \sprintf('volume stopped: %.2f%%', ($totalVolume / $position->size) * 100),
        ]);

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
