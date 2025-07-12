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
    public const string START_FROM_PNL_PERCENT = 'pnl-greater-than';

    private array $filterCallbacks = [];

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->addOption(self::FILTER_CALLBACKS_OPTION, null, InputOption::VALUE_REQUIRED, 'Filter callbacks')
            ->addOption(self::PERCENT_OPTION, null, InputOption::VALUE_REQUIRED, 'Percent', self::DEFAULT_PERCENT)
            ->addOption(self::START_FROM_PNL_PERCENT, null, InputOption::VALUE_REQUIRED, 'If pnl percent greater than ...')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        if ($filterCallbacksOption = $this->paramFetcher->getStringOption(self::FILTER_CALLBACKS_OPTION, false)) {
            $this->filterCallbacks = array_map('trim', explode(',', $filterCallbacksOption));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $positionPart = $this->paramFetcher->requiredFloatOption(self::PERCENT_OPTION);
        $positionPart = new Percent($positionPart);
        $ifPnlGreaterThan = $this->paramFetcher->floatOption(self::START_FROM_PNL_PERCENT);

        $symbolsRaw = SymbolHelper::symbolsToRawValues(...$this->getSymbols());

        $res = [];
        $allPositions = $this->positionService->getAllPositions();
        $lastPrices = $this->positionService->getLastMarkPrices();

        foreach ($allPositions as $symbolRaw => $positions) {
            if (!in_array($symbolRaw, $symbolsRaw, true)) {
                continue;
            }

            $markPrice = $lastPrices[$symbolRaw];

            foreach ($positions as $position) {
                if ($ifPnlGreaterThan) {
                    $pnlPercent = $markPrice->getPnlPercentFor($position);
                    if ($pnlPercent < $ifPnlGreaterThan) {
                        continue;
                    }
                }

                if (!$this->applyFilters($position)) {
                    continue;
                }

                $res[] = $position;
            }
        }

        foreach ($res as $position) {
            $this->orderService->closeByMarket($position, $positionPart->of($position->size));
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
