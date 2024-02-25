<?php

namespace App\Command\Hedge;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Command\AbstractCommand;
use App\Command\Mixin\CommandRunnerCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Value\Percent\Percent;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function explode;
use function sprintf;

#[AsCommand(name: 'hedge:info')]
class HedgeInfoCommand extends AbstractCommand
{
    use SymbolAwareCommand;
    use CommandRunnerCommand;

    public const MAIN_POSITION_IM_PERCENT_FOR_SUPPORT_OPTION = 'supportMainIMPercent';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addOption(self::MAIN_POSITION_IM_PERCENT_FOR_SUPPORT_OPTION, 'm', InputOption::VALUE_OPTIONAL, 'Main position IM to calculate $applicableSupportSize');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $this->getSymbol();
        $positions = $this->positionService->getPositions($symbol);
        if (!($hedge = $positions[0]->getHedge())) {
            return Command::INVALID;
        }
        $supportPosition = $hedge->supportPosition;
        $specifiedMainPositionIMPercentToSupport = $this->paramFetcher->percentOption(self::MAIN_POSITION_IM_PERCENT_FOR_SUPPORT_OPTION);

        $mainPositionIMPercentToSupport = $specifiedMainPositionIMPercentToSupport ? new Percent($specifiedMainPositionIMPercentToSupport) : $this->hedgeService->getDefaultMainPositionIMPercentToSupport();
        $applicableSupportSize = $this->hedgeService->getApplicableSupportSize($hedge, $mainPositionIMPercentToSupport);
        $sizeDiff = $supportPosition->size - $applicableSupportSize;

        $this->io->block(sprintf('Support size applicable for support %s of mainPosition.InitialMargin: %.3f', $mainPositionIMPercentToSupport, $applicableSupportSize));
        $this->io->block(sprintf('Current support size: %.3f', $supportPosition->size));
        $this->io->block(sprintf('Diff: %.3f', $sizeDiff));

        if ($sizeDiff > 0) {
            if ($this->io->confirm(sprintf('Do you want to add stops for this size diff? [%.3f]', $sizeDiff), false)) {
                $range = $this->getRange($this->io->ask('Provide `sl:grid` range:', '-10%..10%'));
                $slGridOptions = $this->io->ask('Provide `sl:grid` options:', '');
                $cmd = sprintf('php bin/console sl:grid %s -f%s -t%s %.3f %s',
                    $supportPosition->side->value,
                    $range[0], $range[1],
                    $sizeDiff,
                    $slGridOptions,
                );

                if ($this->io->confirm(sprintf('`%s`. Sure?', $cmd), false)) {
                    self::cmd($cmd);
                }
            }
        }

        return Command::SUCCESS;
    }

    private function getRange(string $input): array
    {
        $input = explode('..', $input);
        if (count($input) !== 2) {
            throw new InvalidArgumentException('Invalid range provided');
        }

        $from = $this->getRangeValue($input[0], 'from');
        $to = $this->getRangeValue($input[1], 'to');

        return [$from, $to];
    }

    protected function getRangeValue(string $input, string $name): string
    {
        try {
            $percent = $this->paramFetcher->fetchPercentValue($input, $name, 'option');
            return $percent . '%';
        } catch (InvalidArgumentException) {
            try {
                return $this->paramFetcher->fetchFloatValue($input, $name, 'option');
            } catch (InvalidArgumentException $e) {
                throw $e;
            }
        }
    }

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly HedgeService $hedgeService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
