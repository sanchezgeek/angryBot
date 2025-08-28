<?php

namespace App\Stop\Console;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
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
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\TradingStyle;
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

    public const string MODE_OPTION = 'mode';
    public const string DANGER_MODE = 'danger';

    public const string TRADING_STYLE = 'style';
    public const string FROM_PNL_PERCENT_OPTION = 'from-percent';
    public const string POSITION_PART = 'part';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument(self::TRADING_STYLE, InputArgument::OPTIONAL, 'Trading style', TradingStyle::Conservative->value)
            ->addOption(self::FROM_PNL_PERCENT_OPTION, null, InputOption::VALUE_OPTIONAL, 'Apply from specified PNL%')
            ->addOption(self::MODE_OPTION, null, InputOption::VALUE_OPTIONAL, 'Mode')
            ->addOption(self::POSITION_PART, null, InputOption::VALUE_OPTIONAL, '', '100')
        ;

        CreateStopsGridCommand::configureStopsGridArguments($this);
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->tradingStyle = TradingStyle::from($this->paramFetcher->getStringArgument(self::TRADING_STYLE));
        $this->part = $this->paramFetcher->floatOption(self::POSITION_PART);

        try {
            $this->fromPnlPercent = $this->paramFetcher->percentOption(self::FROM_PNL_PERCENT_OPTION);
        } catch (InvalidArgumentException) {
            $value = $providedValue = $this->paramFetcher->getStringOption(self::FROM_PNL_PERCENT_OPTION);

            if (str_starts_with($providedValue, '-')) {
                $value = substr($providedValue, 1);
            }

            if (!PriceDistanceSelector::tryFrom($value)) {
                throw new InvalidArgumentException(sprintf('%s must be one of pnl%% or PriceDistanceSelector', self::FROM_PNL_PERCENT_OPTION));
            }

            $this->fromPnlPercent = $providedValue;
        }

        $this->uniqueId = $this->uniqueIdGenerator->generateUniqueId('sl-grid');
    }

    private TradingStyle $tradingStyle;
    private null|float|string $fromPnlPercent;
    private float $part;
    private string $uniqueId;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $this->paramFetcher->getStringOption(self::MODE_OPTION, false);

        $symbols = $this->getSymbols();

        $symbolsRaw = SymbolHelper::symbolsToRawValues(...$symbols);

        /** @var ByBitLinearPositionService $positionService */
        $positionService = $this->positionService;
        $positions = array_intersect_key(
            $positionService->getPositionsWithLiquidation(),
            array_flip($symbolsRaw)
        );
        $markPrices = $this->positionService->getLastMarkPrices();

        $context = ['uniqid' => $this->uniqueId];
        if ($additionalContext = $this->getAdditionalStopContext()) {
            $context = array_merge($context, $additionalContext);
        }

        if ($oppositeBuyOrdersDistance = $this->getOppositeOrdersDistanceOption()) {
            $context[Stop::OPPOSITE_ORDERS_DISTANCE_CONTEXT] = $oppositeBuyOrdersDistance;
        }

        if ($mode === self::DANGER_MODE) {
            $this->tradingStyle = TradingStyle::Cautious;
            if ($this->fromPnlPercent === null) {
                $this->fromPnlPercent = sprintf('-%s', PriceDistanceSelector::VeryShort->value);
            }
        }

        foreach ($positions as $position) {
            $priceToRelate = match ($mode) {
                self::DANGER_MODE => $markPrices[$position->symbol->name()],
                default => $position->entryPrice()
            };

            $this->doApply($position, $priceToRelate, $context);
        }

        $this->io->writeln(UndoHelper::stopsUndoOutput($this->uniqueId));

        return Command::SUCCESS;
    }

    private function doApply(
        Position $position,
        SymbolPrice $priceToRelate,
        array $context
    ): void {
        $symbol = $position->symbol;
        $side = $position->side;

        $stopsGridsDef = $this->stopsGridDefinitionFinder->create($symbol, $side, $priceToRelate, $this->tradingStyle, $this->fromPnlPercent);

        if (
            $stopsGridsDef->isFoundAutomaticallyFromTa()
            && !$this->io->confirm(sprintf('Stops grid definition for %s: `%s`. Confirm?', $symbol->name(), $stopsGridsDef))
        ) {
            return;
        }

        $this->handler->handle(
            new ApplyStopsToPositionEntryDto(
                $symbol,
                $side,
                new Percent($this->part)->of($position->size),
                $stopsGridsDef,
                $context,
            )
        );
    }

    public function __construct(
        ByBitLinearPositionService $positionService,
        private readonly OpenPositionStopsGridsDefinitions $stopsGridDefinitionFinder,
        private readonly ApplyStopsToPositionHandler $handler,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
