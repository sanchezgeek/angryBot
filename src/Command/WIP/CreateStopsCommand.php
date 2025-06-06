<?php

namespace App\Command\WIP;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sl:create-stops')]
class CreateStopsCommand extends AbstractCommand
{
    use PositionAwareCommand;

    // @todo | symbol
    private const float DEFAULT_INITIAL_VOLUME = 0.001;
    private const int DEFAULT_TRIGGER_DELTA = 1;
    private const int DEFAULT_STEP = 13;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument('volume', InputArgument::REQUIRED, 'Create stops for volume')
            ->addOption('step', 's', InputOption::VALUE_OPTIONAL, \sprintf('Step (default: %s)', self::DEFAULT_STEP), self::DEFAULT_STEP)
            ->addOption('trigger_delta', 't', InputOption::VALUE_OPTIONAL, \sprintf('Trigger delta (default: %s)', self::DEFAULT_TRIGGER_DELTA), self::DEFAULT_TRIGGER_DELTA)
            ->addOption('increment', 'i', InputOption::VALUE_OPTIONAL, 'Increment (optional; default: 0.000)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $symbol = $this->getSymbol();

            if (!(string)($volume = $input->getArgument('volume'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $volume provided (%s)', $volume),
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

            $context = ['uniqid' => $this->uniqueIdGenerator->generateUniqueId('fix-position')];

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
                    $this->stopService->create($position->symbol, $position->side, $price, $volume, $triggerDelta, $context);
                    $volume = $symbol->roundVolume($volume+$increment);
                }
            } else {
                for ($price = $fromPrice; $price < $toPrice; $price+=$step) {
                    $this->stopService->create($position->symbol, $position->side, $price, $volume, $triggerDelta, $context);
                    $volume = $symbol->roundVolume($volume+$increment);
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
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
