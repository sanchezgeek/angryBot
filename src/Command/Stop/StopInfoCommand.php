<?php

namespace App\Command\Stop;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Pnl;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
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

use function array_combine;
use function array_fill;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_replace;
use function count;
use function end;
use function implode;
use function is_bool;
use function sprintf;
use function var_dump;

#[AsCommand(name: 'sl:info')]
class StopInfoCommand extends AbstractCommand
{
    use PositionAwareCommand;

    private const DEFAULT_PNL_STEP = 10;
    private const SHOW_PNL_OPTION = 'showPnl';
    private const SHOW_SIZE_LEFT_OPTION = 'showSize';
    private const SHOW_POSITION_PNL = 'showPositionPnl';
    private const SHOW_TP = 'showTP';
    private const IM_VALUE = 'imValue';

    private string $aggregateWith;
    private array $aggOrder = [];

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption('pnlStep', '-p', InputOption::VALUE_REQUIRED, 'Pnl step (%)', (string)self::DEFAULT_PNL_STEP)
            ->addOption('aggregateWith', null, InputOption::VALUE_REQUIRED, 'Additional stops aggregate callback', '')
            ->addOption(self::SHOW_SIZE_LEFT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Short size left', false)
            ->addOption(self::SHOW_PNL_OPTION, null, InputOption::VALUE_NEGATABLE, 'Short pnl', false)
            ->addOption(self::SHOW_POSITION_PNL, 'i', InputOption::VALUE_NEGATABLE, 'Current position pnl', false)
            ->addOption(self::SHOW_TP, '-t', InputOption::VALUE_NEGATABLE, 'Show TP orders', false)
            ->addOption(self::IM_VALUE, null, InputOption::VALUE_OPTIONAL, 'Position IM value to check')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->aggregateWith = $this->paramFetcher->getStringOption('aggregateWith', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $position = $this->getPosition();
        $pnlStep = $this->paramFetcher->getIntOption('pnlStep');
        $showPnl = $this->paramFetcher->getBoolOption(self::SHOW_PNL_OPTION);
        $showSizeLeft = $this->paramFetcher->getBoolOption(self::SHOW_SIZE_LEFT_OPTION);
        $showCurrentPositionPnl = $this->paramFetcher->getBoolOption(self::SHOW_POSITION_PNL);
        $showTPs = $this->paramFetcher->getBoolOption(self::SHOW_TP);

        if ($imValue = $this->paramFetcher->floatOption(self::IM_VALUE)) {
            var_dump($position->initialMargin->value() > $imValue);die;
        }

        if ($position->isSupportPosition()) {
            $this->io->note(sprintf('%s (hedge support) size: %.3f', $position->getCaption(), $position->size));
            $showSizeLeft = true;
        } else {
            $this->io->note(sprintf('%s lD: %.3f', $position->getCaption(), $position->liquidationPrice - $position->entryPrice));
//            $this->io->note(sprintf('%s size: %.3f', $position->getCaption(), $position->size));
        }

        $stops = $this->stopRepository->findActive(
            side: $position->side,
            qbModifier: static fn (QueryBuilder $qb) => $qb->orderBy($qb->getRootAliases()[0] . '.price', $position->side->isShort() ? 'DESC' : 'ASC')
        );

        if (!$stops) {
            $this->io->block('Stops not found!'); return Command::SUCCESS;
        }

        $stops = (new StopsCollection(...$stops));
        if (!$showTPs) {
            $stops = $stops->filterWithCallback(static fn (Stop $stop) => !$stop->isTakeProfitOrder());
        }
        $rangesCollection = new PositionStopRangesCollection($position, $stops, $pnlStep);

        $stopped = [];
        if ($position->isShort()) {
            foreach ($rangesCollection as $rangeStops) {
                if (!$rangeStops->totalCount()) continue;
                foreach ($stopped as $key => $item) {
                    $stopped[$key] += $rangeStops->totalVolume();
                }
                $stopped[] = $rangeStops->totalVolume();
            }
        } else {
            foreach ($rangesCollection as $rangeStops) {
                if (!$rangeStops->totalCount()) continue;
                $stopped[] = $rangeStops->totalVolume() + (count($stopped) > 0 ? end($stopped) : 0);
            }
        }

        $key = $totalUsdPnL = $totalVolume = 0;
        foreach ($rangesCollection as $rangeDesc => $rangeStops) {
            if (!$rangeStops->totalCount()) {
                continue;
            }

            $usdPnL = $rangeStops->totalUsdPnL($position);

            $format = '[%s] | % 3.d | % 4.1f%%';
            $args = [$rangeDesc, $rangeStops->totalCount(), $rangeStops->volumePart($position->size)];

            if ($showPnl) {
                $format .= ' (%s)';
                $args[] = new Pnl($usdPnL, $position->symbol->associatedCoin()->value);
            }

            if ($showSizeLeft) {
                $sizeLeft = $position->size - $stopped[$key];
                $format .= ' => %.3f'; $args[] = $sizeLeft;
            }

            $this->io->text(\sprintf($format, ...$args));
            $this->printAggregateInfo($rangeStops);

            $totalUsdPnL += $usdPnL;
            $totalVolume += $rangeStops->totalVolume();

            $key++;
        }

        if ($showPnl) {
            $this->io->block(sprintf('total PNL: %s', new Pnl($totalUsdPnL)));
        }

        if ($showCurrentPositionPnl) {
            $this->io->text(
                sprintf('unrealized position PNL: %.1f', $position->unrealizedPnl)
            );
        }

        $this->io->info(sprintf('volume stopped: %.2f%%', ($totalVolume / $position->size) * 100));

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
        CalcPositionLiquidationPriceHandler $calcPositionLiquidationPriceHandler,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
