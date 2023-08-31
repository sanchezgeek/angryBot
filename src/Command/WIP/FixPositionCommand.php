<?php

namespace App\Command\WIP;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Helper\VolumeHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'sl:fix-position')]
class FixPositionCommand extends Command
{
    private const DEFAULT_INITIAL_VOLUME = 0.001;
    private const DEFAULT_TRIGGER_DELTA = 1;
    private const DEFAULT_STEP = 13;

    public function __construct(
        private readonly StopService $stopService,
        private readonly StopRepository $stopRepository,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
//            ->addOption('volume', 'v', InputOption::VALUE_OPTIONAL, \sprintf('Initial volume (default: %s)', self::DEFAULT_INITIAL_VOLUME), self::DEFAULT_INITIAL_VOLUME)
            ->addOption('step', 's', InputOption::VALUE_OPTIONAL, \sprintf('Step (default: %s)', self::DEFAULT_STEP), self::DEFAULT_STEP)
            ->addOption('trigger_delta', 't', InputOption::VALUE_OPTIONAL, \sprintf('Trigger delta (default: %s)', self::DEFAULT_TRIGGER_DELTA), self::DEFAULT_TRIGGER_DELTA)
            ->addOption('increment', 'i', InputOption::VALUE_OPTIONAL, 'Increment (optional; default: 0.000)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

//        $support = $this->positionService->getOpenedPositionInfo($symbol, Side::Buy);
//        $main = $this->positionService->getOpenedPositionInfo($symbol, Side::Sell);
//
//        if (!$support || !$main) {
//
//        }

        try {
            $side = $input->getArgument('position_side');
            if (!$positionSide = Side::tryFrom($side)) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $positionSide provided (%s)', $side),
                );
            }

            if (!($step = (float)$input->getOption('step'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $step),
                );
            }

            if (!($triggerDelta = (float)$input->getOption('trigger_delta'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $triggerDelta provided (%s)', $step),
                );
            }

            $volume = self::DEFAULT_INITIAL_VOLUME;
            $increment = (float)($input->getOption('increment') ?? null);

            $context = ['uniqid' => \uniqid('fix-position', true)];

            $symbol = Symbol::BTCUSDT;
            $ticker = $this->exchangeService->ticker($symbol);
            $position = $this->positionService->getPosition($symbol, $positionSide);

            if ($ticker->isIndexAlreadyOverStop($positionSide, $position->entryPrice)) {
                throw new \LogicException('Index price already over stop');
            }

            $fromPrice = $ticker->indexPrice;

            if ($firstStop = $this->stopRepository->findFirstPositionStop($position)) {
                $toPrice = $firstStop->getPrice();
            } else {
                $toPrice = $position->entryPrice - 200;
            }

            if ($fromPrice > $toPrice) {
                for ($price = $fromPrice; $price > $toPrice; $price-=$step) {
                    $this->stopService->create(
                        $positionSide,
                        $price,
                        $volume,
                        $triggerDelta,
                        $context
                    );
                    $volume = VolumeHelper::round($volume+$increment);
                }
            } else {
                for ($price = $fromPrice; $price < $toPrice; $price+=$step) {
                    $this->stopService->create(
                        $positionSide,
                        $price,
                        $volume,
                        $triggerDelta,
                        $context
                    );
                    $volume = VolumeHelper::round($volume+$increment);
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
