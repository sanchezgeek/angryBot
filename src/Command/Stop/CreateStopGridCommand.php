<?php

namespace App\Command\Stop;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Infrastructure\ByBit\PositionService;
use App\Bot\Application\Service\Orders\StopService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'sl:grid', description: 'Creates incremental SL\'ses grid.')]
class CreateStopGridCommand extends Command
{
    public function __construct(
        private readonly StopService $stopService,
        private readonly StopRepository $stopRepository,
        private readonly PositionService $positionService,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
            ->addArgument('trigger_delta', InputArgument::REQUIRED, 'Trigger delta')
            ->addArgument('volume', InputArgument::REQUIRED, 'Stop start volume')
            ->addArgument('from_price', InputArgument::REQUIRED, 'From price')
            ->addArgument('to_price', InputArgument::REQUIRED, 'To price')
            ->addArgument('step', InputArgument::REQUIRED, 'Step')
            ->addArgument('increment', InputArgument::OPTIONAL, 'Volume to add to each new SL')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = Symbol::BTCUSDT;

        try {
            $positionSide = $input->getArgument('position_side');
            $triggerDelta = $input->getArgument('trigger_delta');
            $volume = $input->getArgument('volume');
            $fromPrice = $input->getArgument('from_price');
            $toPrice = $input->getArgument('to_price');
            $step = $input->getArgument('step');
            $increment = $input->getArgument('increment');

            if (!$positionSide = Side::tryFrom($positionSide)) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $step),
                );
            }
            if (!(float)$step) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $step),
                );
            }
            if (!(float)$triggerDelta) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $triggerDelta provided (%s)', $step),
                );
            }
            if (!(float)$toPrice) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $toPrice provided (%s)', $toPrice),
                );
            }
            if (!(float)$fromPrice) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $fromPrice provided (%s)', $fromPrice),
                );
            }
            if (!(string)$volume) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $price provided (%s)', $volume),
                );
            }

            $context = ['uniqid' => $uniqueId = \uniqid('inc-create', true)];


            $position = $this->positionService->getPosition($symbol, $positionSide);

            $alreadyStopped = 0;
            $stops = $this->stopRepository->findActive($positionSide);
            foreach ($stops as $stop) {
                $alreadyStopped += $stop->getVolume();
            }

            if ($position->size < $alreadyStopped) {
                if (
                    !$io->confirm(
                        \sprintf('All position volume already under SL\'ses. Last position SL\'ses will be removed. Want to continue? ')
                    )
                ) {
                    return Command::FAILURE;
                }
            }

            if ($fromPrice > $toPrice) {
                [$fromPrice, $toPrice] = [$toPrice, $fromPrice];
            }

            for ($price = $fromPrice; $price < $toPrice; $price+=$step) {
                $this->stopService->create(
                    $positionSide,
                    $price,
                    $volume,
                    $triggerDelta,
                    $context
                );
                $volume = round($volume+$increment, 3);
            }

            $sum = 0;
            $stops = $this->stopRepository->findActive(
                side: $positionSide,
                qbModifier: function (QueryBuilder $qb) use ($positionSide) {
                    $priceField = $qb->getRootAliases()[0] . '.price';

                    $qb->orderBy($priceField, $positionSide === Side::Sell ? 'DESC' : 'ASC');
                }
            );

            foreach ($stops as $stop) {
                $sum += $stop->getVolume();
            }

            $delta = $sum - $position->size;


            /** @var Stop[] $removed */
            $removed = [];
            while ($delta > 0) {
                $stop = \array_shift($stops);
                $delta -= $stop->getVolume();
                $this->stopRepository->remove($stop);
                $removed[] = $stop;
            }

            $result = [
                \sprintf('SL\'ses uniqueID: %s', $uniqueId),
            ];

            if ($removed) {
                $volume = 0;
                foreach ($removed as $stop) {
                    $volume+= $stop->getVolume();
                }

                $result[] = \sprintf('Removed volume: %.2f', $volume);
            }

            $io->success($result);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
