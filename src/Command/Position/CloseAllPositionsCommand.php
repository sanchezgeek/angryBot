<?php

namespace App\Command\Position;

use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Position;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
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

    public const string FILTER_CALLBACKS_OPTION = 'fC';

    private array $filterCallbacks = [];

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->addOption(self::FILTER_CALLBACKS_OPTION, null, InputOption::VALUE_REQUIRED, 'Filter callbacks')
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
        $symbolsRaw = SymbolHelper::symbolsToRawValues(...$this->getSymbols());
        var_dump($symbolsRaw);die;

        $res = [];
        foreach ($this->positionService->getAllPositions() as $symbolRaw => $positions) {
            if (!in_array($symbolRaw, $symbolsRaw, true)) {
                continue;
            }

            foreach ($positions as $position) {
                if (!$this->applyFilters($position)) {
                    continue;
                }

                $res[] = $position;
            }
        }

        foreach ($res as $position) {
            $this->orderService->closeByMarket($position, $position->size);
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
