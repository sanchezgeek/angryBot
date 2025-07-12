<?php

namespace App\Stop\Console;

use App\Bot\Domain\Position;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Stop\Application\UseCase\MoveStops\MoveStopsToBreakevenEntryDto;
use App\Stop\Application\UseCase\MoveStops\MoveStopsToBreakevenHandlerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:stops:move-to-breakeven')]
class MoveStopsToBreakevenCommand extends AbstractCommand implements SymbolDependentCommand
{
    use ConsoleInputAwareCommand;
    use SymbolAwareCommand;

    public const string DEFAULT_TARGET_PNL_PERCENT = '0';

    public const string START_FROM_PNL_PERCENT = 'pnl-greater-than';
    public const string TARGET_PNL_PERCENT = 'target-pnl-percent';
    public const string EXCLUDE_FIXATIONS_STOP = 'exclude-fixations';

    private bool $excludeFixationsStop;
    private float $positionPnlPercent;

    protected function configure(): void
    {
        $this
            ->addOption(self::START_FROM_PNL_PERCENT, null, InputOption::VALUE_REQUIRED, 'If pnl percent greater than ...')
            ->addOption(self::TARGET_PNL_PERCENT, null, InputOption::VALUE_REQUIRED, 'PNL% related to position to use as new stops level (delta will be calculated from first stop and applied to other stops)', self::DEFAULT_TARGET_PNL_PERCENT)
            ->addOption(self::EXCLUDE_FIXATIONS_STOP, null, InputOption::VALUE_NEGATABLE, 'Stops created as fixations after loss will be omitted while finding first position stop', false)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->excludeFixationsStop = $this->paramFetcher->getBoolOption(self::EXCLUDE_FIXATIONS_STOP);
        $this->positionPnlPercent = $this->paramFetcher->requiredFloatOption(self::TARGET_PNL_PERCENT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ifPnlGreaterThan = $this->paramFetcher->requiredFloatOption(self::START_FROM_PNL_PERCENT);

        $allPositions = $this->positionService->getAllPositions();
        $lastPrices = $this->positionService->getLastMarkPrices();

        $candidates = [];
        foreach ($allPositions as $symbolRaw => $positions) {
            $markPrice = $lastPrices[$symbolRaw];

            foreach ($positions as $position) {
                $pnlPercent = $markPrice->getPnlPercentFor($position);
                if ($pnlPercent < $ifPnlGreaterThan) {
                    continue;
                }

                $candidates[] = $position;
            }
        }

        foreach ($candidates as $candidate) {
            $this->moveStopsToBreakevenHandler->handle(
                $this->getEntryDto($candidate)
            );
        }

        return Command::SUCCESS;
    }

    private function getEntryDto(Position $position): MoveStopsToBreakevenEntryDto
    {
        return new MoveStopsToBreakevenEntryDto(
            $position,
            $this->positionPnlPercent,
            $this->excludeFixationsStop
        );
    }

    public function __construct(
        private readonly ByBitLinearPositionService $positionService,
        private readonly MoveStopsToBreakevenHandlerInterface $moveStopsToBreakevenHandler,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
