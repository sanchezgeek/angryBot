<?php

namespace App\Command\WIP;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Helper\VolumeHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sl:fix-position')]
class FixPositionCommand extends AbstractCommand
{
    use PositionAwareCommand;

    private const DEFAULT_INITIAL_VOLUME = 0.001;
    private const DEFAULT_TRIGGER_DELTA = 1;
    private const DEFAULT_STEP = 13;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
//            ->addOption('volume', 'v', InputOption::VALUE_OPTIONAL, \sprintf('Initial volume (default: %s)', self::DEFAULT_INITIAL_VOLUME), self::DEFAULT_INITIAL_VOLUME)
            ->addOption('step', 's', InputOption::VALUE_OPTIONAL, \sprintf('Step (default: %s)', self::DEFAULT_STEP), self::DEFAULT_STEP)
            ->addOption('trigger_delta', 't', InputOption::VALUE_OPTIONAL, \sprintf('Trigger delta (default: %s)', self::DEFAULT_TRIGGER_DELTA), self::DEFAULT_TRIGGER_DELTA)
            ->addOption('increment', 'i', InputOption::VALUE_OPTIONAL, 'Increment (optional; default: 0.000)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
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

            $position = $this->getPosition();
            $ticker = $this->exchangeService->ticker($position->symbol);

            if ($ticker->isIndexAlreadyOverStop($position->side, $position->entryPrice)) {
                throw new \LogicException('Index price already over stop');
            }

            $fromPrice = $ticker->indexPrice->value();

            if ($firstStop = $this->stopRepository->findFirstPositionStop($position)) {
                $toPrice = $firstStop->getPrice();
            } else {
                $toPrice = $position->entryPrice - 200;
            }

            if ($fromPrice > $toPrice) {
                for ($price = $fromPrice; $price > $toPrice; $price-=$step) {
                    $this->stopService->create($position->side, $price, $volume, $triggerDelta, $context);
                    $volume = VolumeHelper::round($volume+$increment);
                }
            } else {
                for ($price = $fromPrice; $price < $toPrice; $price+=$step) {
                    $this->stopService->create($position->side, $price, $volume, $triggerDelta, $context);
                    $volume = VolumeHelper::round($volume+$increment);
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    public function __construct(
        private readonly StopService $stopService,
        private readonly StopRepository $stopRepository,
        private readonly ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
