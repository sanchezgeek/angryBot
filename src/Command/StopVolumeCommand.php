<?php

namespace App\Command;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Service\Stop\StopService;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class StopVolumeCommand extends Command
{
    use LoggerTrait;

    private const DEFAULT_INITIAL_VOLUME = 0.001;
    private const DEFAULT_TRIGGER_DELTA = 1;
    private const DEFAULT_STEP = 13;

    protected static $defaultName = 'stop-volume';

    public function __construct(
        private readonly StopService $stopService,
        private readonly StopRepository $stopRepository,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
        LoggerInterface $logger,
        string $name = null,
    ) {
        $this->logger = $logger;

        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
            ->addArgument('volume', InputArgument::REQUIRED, 'Create stops for volume')
            ->addOption('fromPrice', 'f', InputOption::VALUE_OPTIONAL, 'Price to starts from (default - ticker.indexPrice)')
            ->addOption('toPrice', 't', InputOption::VALUE_OPTIONAL, 'Price to finish with (default - position.entryPrice)')
            ->addOption('delta', 'd', InputOption::VALUE_OPTIONAL, 'toPrice = (ticker.indexPosition + delta)')
//            ->addOption('trigger_delta', 't', InputOption::VALUE_OPTIONAL, \sprintf('Trigger delta (default: %s)', self::DEFAULT_TRIGGER_DELTA), self::DEFAULT_TRIGGER_DELTA)
//            ->addOption('increment', 'i', InputOption::VALUE_OPTIONAL, 'Increment (optional; default: 0.000)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if (!$positionSide = Side::tryFrom($input->getArgument('position_side'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $input->getArgument('position_side')),
                );
            }

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

            $context = ['uniqid' => \uniqid('fix-position', true)];

            $symbol = Symbol::BTCUSDT;
            $ticker = $this->exchangeService->getTicker($symbol);
            $position = $this->positionService->getPosition($symbol, $positionSide);

            if ($fromPrice && $ticker->isIndexAlreadyOverStop($positionSide, $fromPrice)) {
                if (!$io->confirm(\sprintf('ticker.indexPrice already over specified $fromPrice. Do you want to use current ticker.indexPrice as $fromPrice?'))) {
                    return Command::FAILURE;
                }
                $fromPrice = $ticker->indexPrice;
            }

            if (!$fromPrice) {
                $fromPrice = $ticker->indexPrice;
            }

            if ($delta) {
                $toPrice = $position->side === Side::Sell ? $ticker->indexPrice + $delta : $ticker->indexPrice - $delta;
            }

            if ($toPrice && $ticker->isIndexAlreadyOverStop($positionSide, $toPrice)) {
                if (!$io->confirm(\sprintf('ticker.indexPrice already over specified $toPrice. Do you want to change $toPrice?'))) {
                    return Command::FAILURE;
                }

                if (!\floatval($delta = $io->ask(\sprintf('Please enter delta:')))) {
                    throw new \InvalidArgumentException(
                        \sprintf('Invalid $delta provided (%s)', $delta),
                    );
                }

                $delta = (float)$delta;

                $toPrice = $position->side === Side::Sell ? $ticker->indexPrice + $delta : $ticker->indexPrice - $delta;
            }

            $info = $this->stopService->createIncrementalToPosition(
                $position,
                $volume,
                $fromPrice,
                $toPrice,
                $context
            );

            $io->info([
                \sprintf('IncrementalStopGrid for %s created.', $position->getCaption()),
                \json_encode($info),
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
