<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\Hedge;
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

use function array_combine;
use function array_fill;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_replace;
use function count;
use function implode;
use function is_bool;
use function sprintf;

#[AsCommand(name: 'sl:info')]
class StopInfoCommand extends Command
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;

    private const DEFAULT_PNL_STEP = 20;
    private const SHOW_PNL_OPTION = 'showPnl';
    private const SHOW_POSITION_PNL = 'showPositionPnl';

    private SymfonyStyle $io;
    private string $aggregateWith;
    private array $aggOrder = [];

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption('pnlStep', '-p', InputOption::VALUE_REQUIRED, 'Pnl step (%)', (string)self::DEFAULT_PNL_STEP)
            ->addOption('aggregateWith', null, InputOption::VALUE_REQUIRED, 'Additional stops aggregate callback', '')
            ->addOption(self::SHOW_PNL_OPTION, null, InputOption::VALUE_NEGATABLE, 'Short pnl', false)
            ->addOption(self::SHOW_POSITION_PNL, 'i', InputOption::VALUE_NEGATABLE, 'Current position pnl', false)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->withInput($input);
        $this->io = new SymfonyStyle($input, $output);

        $this->aggregateWith = $this->paramFetcher->getStringOption('aggregateWith', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $position = $this->getPosition();
        $pnlStep = $this->paramFetcher->getIntOption('pnlStep');
        $showPnl = $this->paramFetcher->getBoolOption(self::SHOW_PNL_OPTION);
        $showCurrentPositionPnl = $this->paramFetcher->getBoolOption(self::SHOW_POSITION_PNL);

        $isHedge = ($oppositePosition = $this->positionService->getOppositePosition($position)) !== null;

        if ($isHedge) {
            $isSupportPosition = Hedge::create($position, $oppositePosition)->isSupportPosition($position);
            if ($isSupportPosition) {
//                $this->io->info(sprintf('[hedge support] size: %.3f', $position->size));
            }
        }

        $stops = $this->stopRepository->findActive(
            side: $position->side,
            qbModifier: static fn (QueryBuilder $qb) => $qb->orderBy($qb->getRootAliases()[0] . '.price', $position->side->isShort() ? 'DESC' : 'ASC')
        );

        if (!$stops) {
            $this->io->info('Stops not found!'); return Command::SUCCESS;
        }

        $stops = new StopsCollection(...$stops);
        $rangesCollection = new PositionStopRangesCollection($position, $stops, $pnlStep);
        $totalUsdPnL = $totalVolume = 0;
        foreach ($rangesCollection as $rangeDesc => $rangeStops) {
            if (!$rangeStops->totalCount()) {
                continue;
            }

            $usdPnL = $rangeStops->totalUsdPnL($position);

            $format = '[%s] | %d | %.1f%%';
            $args = [
                $rangeDesc,
                $rangeStops->totalCount(),
                $rangeStops->volumePart($position->size),
            ];

            if ($showPnl) {
                $format .= ' (%s)';
                $args[] = new Pnl($usdPnL, 'USDT');
            }

            $this->io->note(\sprintf($format, ...$args));
            $this->printAggregateInfo($rangeStops);

            $totalUsdPnL += $usdPnL;
            $totalVolume += $rangeStops->totalVolume();
        }

        if ($showPnl) {
            $this->io->note(sprintf('total PNL: %s', new Pnl($totalUsdPnL)));
        }

        if ($showCurrentPositionPnl) {
            $this->io->note(
                sprintf('unrealized position PNL: %.1f', $position->unrealizedPnl)
            );
        }

        $this->io->note(sprintf('volume stopped: %.2f%%', ($totalVolume / $position->size) * 100));

        return Command::SUCCESS;
    }

    private function printAggregateInfo(StopsCollection $stops): void
    {
        if (!$this->aggregateWith) {
            return;
        }

        $aggResult = [];
        foreach ($stops as $stop) {
            eval('$callbackValue = $stop->' . $this->aggregateWith . ';');
            $key = is_bool($callbackValue) ? ($callbackValue ? 'true' : 'false') : (string)$callbackValue;
            $aggResult[$key] = $aggResult[$key] ?? new StopsCollection();
            $aggResult[$key]->add($stop);
        }

        if ($this->aggOrder) {
            $aggResult = array_filter(
                array_replace(array_combine($this->aggOrder, array_fill(0, count($this->aggOrder), null)), $aggResult),
                static fn ($value) => $value !== null
            );
        }
        $this->aggOrder = array_merge($this->aggOrder, array_keys($aggResult));

        $aggInfo = [];
        foreach ($aggResult as $value => $aggregatedStops) {
            $cPart = $aggregatedStops->totalCount() / $stops->totalCount() * 100;
            $vPart = $aggregatedStops->totalVolume() / $stops->totalVolume() * 100;
            $aggKey = $this->aggregateWith . ' = ' . $value;
            $aggInfo[] = Json::encode([
                $aggKey => [
                    'qnt' => $aggregatedStops->totalCount(),
                    'cPart' => sprintf('%s%%', PriceHelper::round($cPart, 1)),
                    'vPart' => sprintf('%s%%', PriceHelper::round($vPart, 1)),
                ],
            ]);
        }

        $this->io->info(implode("\n", $aggInfo));
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
