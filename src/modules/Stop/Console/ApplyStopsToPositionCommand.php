<?php

declare(strict_types=1);

namespace App\Stop\Console;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Command\AbstractCommand;
use App\Command\Helper\UndoHelper;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OppositeOrdersDistanceAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\PositionDependentCommand;
use App\Command\Stop\CreateStopsGridCommand;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\StopsCollection;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionEntryDto;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionHandler;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use App\Trading\Domain\Symbol\Helper\SymbolHelper;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:stops:apply')]
class ApplyStopsToPositionCommand extends AbstractCommand implements PositionDependentCommand
{
    use ConsoleInputAwareCommand;
    use SymbolAwareCommand;
    use PositionAwareCommand;
    use AdditionalStopContextAwareCommand;
    use OppositeOrdersDistanceAwareCommand;

    public const string FORCE_OPTION = 'force';
    public const string MODE_OPTION = 'mode';

    public const string DANGER_MODE = 'danger';
    public const string FIXATION_MODE = 'fixation';
    public const string AS_AFTER_AUTOOPEN_MODE = 'autoOpen';

    public const string RISK_LEVEL = 'riskLevel';
    public const string FROM_PNL_PERCENT_OPTION = 'from-percent';
    public const string POSITION_PART = 'part';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument(self::RISK_LEVEL, InputArgument::OPTIONAL, 'Trading style', RiskLevel::Conservative->value)
            ->addOption(self::FROM_PNL_PERCENT_OPTION, null, InputOption::VALUE_OPTIONAL, 'Apply from specified PNL%')
            ->addOption(self::MODE_OPTION, null, InputOption::VALUE_OPTIONAL, 'Mode')
            ->addOption(self::POSITION_PART, null, InputOption::VALUE_OPTIONAL)
            ->addOption(self::FORCE_OPTION, null, InputOption::VALUE_NEGATABLE)
        ;

        // fixations context
        // + fixations def
        CreateStopsGridCommand::configureStopsGridArguments($this);
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->riskLevel = RiskLevel::from($this->paramFetcher->getStringArgument(self::RISK_LEVEL));
        $this->part = $this->paramFetcher->floatOption(self::POSITION_PART);

        try {
            $this->fromPnlPercent = $this->paramFetcher->percentOption(self::FROM_PNL_PERCENT_OPTION);
        } catch (InvalidArgumentException) {
            $this->fromPnlPercent = $this->paramFetcher->requiredFloatOption(self::FROM_PNL_PERCENT_OPTION);
        }

        $this->uniqueId = $this->uniqueIdGenerator->generateUniqueId('sl-grid');
        $this->mode = $this->paramFetcher->getStringOption(self::MODE_OPTION, false);
    }

    private RiskLevel $riskLevel;
    private null|float|string $fromPnlPercent;
    private ?float $part;
    private string $uniqueId;
    private bool $force;
    private ?string $mode;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->force = $this->paramFetcher->getBoolOption(self::FORCE_OPTION);

        /** @var ByBitLinearPositionService $positionService */
        $positionService = $this->positionService;

        $symbols = $this->getSymbols();

        if (count($symbols) > 1) {
            $positions = array_intersect_key(
                $positionService->getPositionsWithLiquidation(),
                array_flip(SymbolHelper::symbolsToRawValues(...$symbols))
            );
        } else {
            $positions = array_intersect_key(
                $positionService->getAllPositions(),
                array_flip(SymbolHelper::symbolsToRawValues(...$symbols))
            );

            $side = $this->getPositionSide();

            $positions = [$positions[$symbols[0]->name()][$side->value]];
        }
        $markPrices = $this->positionService->getLastMarkPrices();

        $context = ['uniqid' => $this->uniqueId];
        if ($additionalContext = $this->getAdditionalStopContext()) {
            $context = array_merge($context, $additionalContext);
        }

        if ($oppositeBuyOrdersDistance = $this->getOppositeOrdersDistanceOption()) {
            $context[Stop::OPPOSITE_ORDERS_DISTANCE_CONTEXT] = $oppositeBuyOrdersDistance;
        }

        if ($this->mode === self::FIXATION_MODE) {
            $context = Stop::addStopCreatedAsFixationFlagToContext($context);
        }

        if (in_array($this->mode, [self::DANGER_MODE, self::FIXATION_MODE], true)) {
            $this->riskLevel = RiskLevel::Cautious;

            $this->fromPnlPercent = $this->fromPnlPercent ?? match ($this->mode) {
                self::DANGER_MODE => PriceDistanceSelector::VeryVeryShort->toLossExpr(),
                self::FIXATION_MODE => PriceDistanceSelector::AlmostImmideately->toLossExpr(),
            };
        }

        $addedStopsCount = 0;
        foreach ($positions as $position) {
            $priceToRelate = match ($this->mode) {
                self::DANGER_MODE, self::FIXATION_MODE => $markPrices[$position->symbol->name()],
                default => $position->entryPrice()
            };

            $stopsCollection = $this->doApply($position, $priceToRelate, $context);
            $addedStopsCount += $stopsCollection?->totalCount() ?? 0;
        }

        if ($addedStopsCount) {
            $this->io->writeln(UndoHelper::stopsUndoOutput($this->uniqueId));
        }

        return Command::SUCCESS;
    }

    private function doApply(
        Position $position,
        SymbolPrice $priceToRelate,
        array $context
    ): ?StopsCollection {
        $symbol = $position->symbol;
        $side = $position->side;

        $stopsGridsDef = $this->stopsGrids->standard($symbol, $side, $priceToRelate, $this->riskLevel, $this->fromPnlPercent);

        if (
            $stopsGridsDef->isFoundAutomaticallyFromTa()
            && !$this->io->confirm(sprintf('Stops grid definition for %s: `%s`. $priceToRelate = %s. Confirm?', $symbol->name(), $stopsGridsDef, $priceToRelate))
        ) {
            return null;
        }

        $part = $this->part ?? 100;

        $applyInRange = $stopsGridsDef->factAbsoluteRange();

        $existedStopsInRange = new StopsCollection(...$this->stopRepository->findActiveInRange($symbol, $side, $applyInRange));
        $existedStopsInRange = $existedStopsInRange->filterWithCallback(static fn (Stop $stop) => !$stop->isAdditionalStopFromLiquidationHandler());

        if ($existedStopsInRange->totalCount()) {
            $coveredPart = $existedStopsInRange->volumePart($position->size);
            $newPart = $part - $coveredPart;
            if ($newPart <= 0.00) {
                if (!$this->force) {
                    $this->io->info('All volume already covered');
                    return null;
                }
            } else {
                $part = $newPart;
            }
        }

        return $this->handler->handle(
            new ApplyStopsToPositionEntryDto(
                $symbol,
                $side,
                new Percent($part)->of($position->size),
                $stopsGridsDef,
                $context,
            )
        );
    }

    public function __construct(
        ByBitLinearPositionService $positionService,
        private readonly OpenPositionStopsGridsDefinitions $stopsGrids,
        private readonly ApplyStopsToPositionHandler $handler,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        private readonly StopRepositoryInterface $stopRepository,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
