<?php

namespace App\Command\Stop;

use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionLiquidatedBeforeOrderPriceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionNotFoundException;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactory;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Pnl;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\PriceMovement;
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
use function array_map;
use function array_merge;
use function array_replace;
use function array_reverse;
use function count;
use function implode;
use function is_bool;
use function sprintf;
use function var_dump;

#[AsCommand(name: 'sl:info')]
class StopInfoCommand extends AbstractCommand
{
    use PositionAwareCommand;

    private const DEFAULT_PNL_STEP = 10;
    private const PNL_STEP = 'pnlStep';
    private const AGGREGATE_WITH = 'aggregateWith';
    private const SHOW_PNL_OPTION = 'showPnl';
    private const SHOW_SIZE_LEFT_OPTION = 'showSize';
    private const SHOW_POSITION_PNL = 'showPositionPnl';
    private const SHOW_TP = 'showTP';
    private const SHOW_STATE_CHANGES = 'showState';
    private const SHOW_CUMULATIVE_STATE_CHANGES = 'showCum';
    private const DEBUG_OPTION = 'deb';
    private const IM_VALUE = 'imValue';

    private string $aggregateWith;
    private array $aggOrder = [];

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption(self::PNL_STEP, '-p', InputOption::VALUE_REQUIRED, 'Pnl step (%)', (string)self::DEFAULT_PNL_STEP)
            ->addOption(self::AGGREGATE_WITH, null, InputOption::VALUE_REQUIRED, 'Additional stops aggregate callback', '')
            ->addOption(self::SHOW_SIZE_LEFT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Short size left', false)
            ->addOption(self::SHOW_PNL_OPTION, null, InputOption::VALUE_NEGATABLE, 'Short pnl', false)
            ->addOption(self::SHOW_POSITION_PNL, 'i', InputOption::VALUE_NEGATABLE, 'Current position pnl', false)
            ->addOption(self::SHOW_TP, '-t', InputOption::VALUE_NEGATABLE, 'Show TP orders', false)
            ->addOption(self::IM_VALUE, null, InputOption::VALUE_OPTIONAL, 'Position IM value to check')
            ->addOption(self::DEBUG_OPTION, null, InputOption::VALUE_NEGATABLE, 'Debug?')
            ->addOption(self::SHOW_STATE_CHANGES, null, InputOption::VALUE_NEGATABLE, 'Show state changes?')
            ->addOption(self::SHOW_CUMULATIVE_STATE_CHANGES, null, InputOption::VALUE_NEGATABLE, 'Show cumulative state changes?')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->aggregateWith = $this->paramFetcher->getStringOption(self::AGGREGATE_WITH, false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $this->getSymbol();
        $position = $this->getPosition();
        $positionSide = $this->getPositionSide();
        $pnlStep = $this->paramFetcher->getIntOption('pnlStep');
        $showPnl = $this->paramFetcher->getBoolOption(self::SHOW_PNL_OPTION);
        $showSizeLeft = $this->paramFetcher->getBoolOption(self::SHOW_SIZE_LEFT_OPTION);
        $showCurrentPositionPnl = $this->paramFetcher->getBoolOption(self::SHOW_POSITION_PNL);
        $showTPs = $this->paramFetcher->getBoolOption(self::SHOW_TP);

        if ($imValue = $this->paramFetcher->floatOption(self::IM_VALUE)) {
            var_dump($position->initialMargin->value() > $imValue);die;
        }

        $this->io->note(sprintf('%s size: %.3f', $position->getCaption(), $position->size));
        $this->io->note(sprintf('%s entry: %.2f', $position->getCaption(), $position->entryPrice));
        if ($position->isSupportPosition()) {
            $showSizeLeft = true;
        } else {
            $this->io->note(sprintf('%s lD: %.3f', $position->getCaption(), $position->liquidationDistance()));
            $this->io->note(sprintf('%s l: %.2f', $position->getCaption(), $position->liquidationPrice));
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

        $tradingSandbox = $this->tradingSandboxFactory->byCurrentState($symbol);

        $totalUsdPnL = $totalVolume = 0;
        $initialState = $tradingSandbox->getCurrentState();
        $initialPositionState = $initialState->getPosition($positionSide);

        $output = [];
        $previousSandboxState = $initialState;
        $positionLiquidationRowPrinted = false;
        foreach ($rangesCollection as $rangeDesc => $rangeStops) {
            /** @var StopsCollection $rangeStops */
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

            $positionBeforeRange = $previousSandboxState->getPosition($positionSide);
            $positionLiquidated = false;
            $newSandboxState = null;
            try {
                $tradingSandbox->processOrders(...$rangeStops);
                $newSandboxState = $tradingSandbox->getCurrentState();
            } catch (SandboxPositionLiquidatedBeforeOrderPriceException $e) {
                $positionLiquidated = true;
                (!$positionLiquidationRowPrinted) && $output[] = sprintf('position liquidated at %s', $positionBeforeRange->liquidationPrice);
                $positionLiquidationRowPrinted = true;
            } catch (SandboxPositionNotFoundException $e) {
            }

            $positionAfterRange = $newSandboxState?->getPosition($positionSide);

            if ($showSizeLeft && !$positionLiquidated) {
                if ($positionAfterRange) {
                    $sizeLeft = $positionAfterRange->size; $format .= ' => %.3f';$args[] = $sizeLeft;
                } else {
                    $format .= ' => position not found => position closed?';
                }
            }

            if ($this->isShowStateChangesEnabled()) {
                if ($positionAfterRange) {
                    $this->appendNewPositionStateChanges($format, $args, $positionSide, $rangeStops, $positionBeforeRange, $positionAfterRange);
                }
                if ($positionAfterRange?->isSupportPosition() && !$positionBeforeRange->isSupportPosition()) {
                    $format .= ' | became support?';
                }
                if ($positionAfterRange && $this->isShowCumulativeStateChangesEnabled()) {
                    $this->appendNewPositionStateChanges($format, $args, $positionSide, $rangeStops, $initialPositionState, $positionAfterRange, true);
                }
            }

            $output[] = sprintf($format, ...$args);
            $aggInfo = $this->printAggregateInfo($rangeStops, $positionSide);
            if ($aggInfo !== null) {
                $output[] = $aggInfo;
            }

            $totalUsdPnL += $usdPnL;
            $totalVolume += $rangeStops->totalVolume();

            $previousSandboxState = $newSandboxState ?? $previousSandboxState;
        }

        if ($positionSide->isShort()) {
            $output = array_reverse($output);
        }

        foreach ($output as $line) {
            $this->io->text($line);
        }

        if ($showPnl) {
            $this->io->block(sprintf('total PNL: %s', new Pnl($totalUsdPnL)));
        }

        if ($showCurrentPositionPnl) {
            $this->io->text(
                sprintf('unrealized position PNL: %.1f', $position->unrealizedPnl),
            );
        }

        $this->io->info(sprintf('volume stopped: %.2f%%', ($totalVolume / $position->size) * 100));

        return Command::SUCCESS;
    }

    /**
     * @todo | case when Position is totally closed is not taken into account
     */
    public function appendNewPositionStateChanges(
        string &$format,
        array &$args,
        Side $positionSide,
        StopsCollection $rangeStops,
        Position $positionBeforeRange,
        Position $positionAfterRange,
        bool $isCumInfo = false
    ): void {
        if (!$positionAfterRange->isSupportPosition()) { # new liquidation
            $liquidationDiff = PriceMovement::fromToTarget($positionBeforeRange->liquidationPrice, $positionAfterRange->liquidationPrice);

//            if (!$isCumInfo) {
//                $format .= ' | liq.price: %.2f';
//                $args[] = $positionAfterRange->liquidationPrice;
//            }

            $format .= ' | liq.diff: %s';
            $args[] = $liquidationDiff->percentDeltaForPositionLoss($positionSide, $rangeStops->getAvgPrice())->setOutputDecimalsPrecision(8);
        }
    }

    private function printAggregateInfo(StopsCollection $stops, Side $positionSide): ?string
    {
        if (!$this->aggregateWith) {
            return null;
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
                static fn ($value) => $value !== null,
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

        $aggInfo = array_map(static fn(string $info) => '      ' . $info, $aggInfo);

        if ($positionSide->isShort()) {
            return "\n" . implode("\n", $aggInfo);
        } else {
            return implode("\n", $aggInfo) . "\n";
        }
    }

    private function isDebugEnabled(): bool
    {
        return $this->paramFetcher->getBoolOption(self::DEBUG_OPTION);
    }

    private function isShowStateChangesEnabled(): bool
    {
        return $this->paramFetcher->getBoolOption(self::SHOW_STATE_CHANGES);
    }

    private function isShowCumulativeStateChangesEnabled(): bool
    {
        return $this->paramFetcher->getBoolOption(self::SHOW_CUMULATIVE_STATE_CHANGES);
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        PositionServiceInterface $positionService,
        private readonly TradingSandboxFactory $tradingSandboxFactory,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
