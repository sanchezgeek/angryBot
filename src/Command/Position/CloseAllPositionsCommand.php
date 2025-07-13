<?php

namespace App\Command\Position;

use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Position;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Domain\Symbol\Helper\SymbolHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:close-all')]
class CloseAllPositionsCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use SymbolAwareCommand;

    public const string DEFAULT_PERCENT = '10';

    public const string FILTER_CALLBACKS_OPTION = 'fC';
    public const string PERCENT_OPTION = 'percent';
    public const string PNL_GREATER_THAN = 'pnl-greater-than';
    public const string PNL_LESS_THAN = 'pnl-less-than';
    public const string DRY = 'dry';

    private array $filterCallbacks = [];
    private bool $dry;

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->addOption(self::FILTER_CALLBACKS_OPTION, null, InputOption::VALUE_REQUIRED, 'Filter callbacks')
            ->addOption(self::PERCENT_OPTION, null, InputOption::VALUE_REQUIRED, 'Percent', self::DEFAULT_PERCENT)
            ->addOption(self::PNL_GREATER_THAN, null, InputOption::VALUE_REQUIRED)
            ->addOption(self::PNL_LESS_THAN, null, InputOption::VALUE_REQUIRED)
            ->addOption(self::DRY, null, InputOption::VALUE_NEGATABLE)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        if ($filterCallbacksOption = $this->paramFetcher->getStringOption(self::FILTER_CALLBACKS_OPTION, false)) {
            $this->filterCallbacks = array_map('trim', explode(',', $filterCallbacksOption));
        }

        $this->dry = $this->paramFetcher->getBoolOption(self::DRY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $positionPart = $this->paramFetcher->requiredFloatOption(self::PERCENT_OPTION);
        $positionPart = new Percent($positionPart);
        $ifPnlGreaterThan = $this->paramFetcher->floatOption(self::PNL_GREATER_THAN);
        $ifPnlLessThan = $this->paramFetcher->floatOption(self::PNL_LESS_THAN);

        $symbolsRaw = SymbolHelper::symbolsToRawValues(...$this->getSymbols());

        $candidates = [];
        $allPositions = $this->positionService->getAllPositions();
        $lastPrices = $this->positionService->getLastMarkPrices();

        foreach ($allPositions as $symbolRaw => $positions) {
            if (!in_array($symbolRaw, $symbolsRaw, true)) {
                continue;
            }

            $markPrice = $lastPrices[$symbolRaw];

            foreach ($positions as $position) {
                $pnlPercent = $markPrice->getPnlPercentFor($position);

                if (
                    ($ifPnlGreaterThan && $pnlPercent < $ifPnlGreaterThan)
                    || ($ifPnlLessThan && $pnlPercent > $ifPnlLessThan)
                ) {
                    continue;
                }

                if (!$this->applyFilters($position)) {
                    continue;
                }

                $candidates[] = $position;
            }
        }

        if ($this->dry) {
            $map = array_map(
                static fn (Position $position) => sprintf('%s => %s, %s', $position->getCaption(), $position->unrealizedPnl, $position->initialMargin),
                $candidates
            );

            var_dump($map);
        } else {
            foreach ($candidates as $position) {
                $this->orderService->closeByMarket($position, $positionPart->of($position->size));
            }
        }


        return Command::SUCCESS;
    }

    private function applyFilters(Position $position): bool
    {
        foreach ($this->filterCallbacks as $filterCallback) {
            eval('$callbackResult = $position' . $filterCallback . ';');
            if ($callbackResult !== true) {
                return false;
            }
        }

        return true;
    }

    public function __construct(
        private readonly ByBitLinearPositionService $positionService,
        private readonly OrderServiceInterface $orderService,
        SymbolProvider $symbolProvider,
        ?string $name = null,
    ) {
        $this->symbolProvider = $symbolProvider;

        parent::__construct($name);
    }
}
