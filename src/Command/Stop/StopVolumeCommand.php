<?php

namespace App\Command\Stop;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Position\ValueObject\Side;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sl:volume', description: 'Creates incremental SL\'ses grid.')]
class StopVolumeCommand extends AbstractCommand
{
    use PositionAwareCommand;

    public function __construct(
        private readonly StopService $stopService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument('volume', InputArgument::REQUIRED, 'Create stops for volume')
            ->addOption('fromPrice', 'f', InputOption::VALUE_OPTIONAL, 'Price to starts from (default - ticker.indexPrice)')
            ->addOption('toPrice', 't', InputOption::VALUE_OPTIONAL, 'Price to finish with (default - position.entryPrice)')
            ->addOption('delta', 'd', InputOption::VALUE_OPTIONAL, 'toPrice = (ticker.indexPosition + delta)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if (!(float)($volume = $input->getArgument('volume'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $volume provided (%s)', $volume),
                );
            }

            $fromPrice = $input->getOption('fromPrice');
            if ($fromPrice && !($fromPrice = (float)($fromPrice))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $fromPrice provided (%s)', $input->getOption('fromPrice')),
                );
            }

            $toPrice = $input->getOption('toPrice');
            if ($toPrice && !($toPrice = (float)($toPrice))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $toPrice provided (%s)', $input->getOption('toPrice')),
                );
            }

            $delta = $input->getOption('delta');
            if ($delta && !($delta = (float)($delta))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $delta provided (%s)', $input->getOption('$delta')),
                );
            }

            if ($delta && $toPrice) {
                throw new \LogicException('Only one of $delta and $toPrice can be used');
            }

            $context = ['uniqid' => $this->uniqueIdGenerator->generateUniqueId('fix-position')];

            $position = $this->getPosition();
            $positionSide = $position->side;
            $ticker = $this->exchangeService->ticker($position->symbol);

            if ($fromPrice && $ticker->isIndexAlreadyOverStop($positionSide, $fromPrice)) {
                if (!$this->io->confirm(\sprintf('ticker.indexPrice already over specified $fromPrice. Do you want to use current ticker.indexPrice as $fromPrice?'))) {
                    return Command::FAILURE;
                }
                $fromPrice = $ticker->indexPrice->value();
            }

            if (!$fromPrice) {
                $fromPrice = $ticker->indexPrice->value();
            }

            if ($delta) {
                $toPrice = $position->side === Side::Sell ? $ticker->indexPrice->value() + $delta : $ticker->indexPrice->value() - $delta;
            }

            if ($toPrice && $ticker->isIndexAlreadyOverStop($positionSide, $toPrice)) {
                if (!$this->io->confirm(\sprintf('ticker.indexPrice already over specified $toPrice. Do you want to change $toPrice?'))) {
                    return Command::FAILURE;
                }

                if (!\floatval($delta = $this->io->ask(\sprintf('Please enter delta:')))) {
                    throw new \InvalidArgumentException(
                        \sprintf('Invalid $delta provided (%s)', $delta),
                    );
                }

                $delta = (float)$delta;

                $toPrice = $position->side === Side::Sell ? $ticker->indexPrice->value() + $delta : $ticker->indexPrice->value() - $delta;
            }

            $toPrice = $toPrice ?: $position->entryPrice;

            $info = $this->stopService->createIncrementalToPosition(
                $position,
                $volume,
                $fromPrice,
                $toPrice,
                $context
            );

            $this->io->info([
                \sprintf('IncrementalStopGrid for %s created.', $position->getCaption()),
                \json_encode($info),
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
