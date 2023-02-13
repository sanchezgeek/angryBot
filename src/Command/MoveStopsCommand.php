<?php

namespace App\Command;

use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Infrastructure\ByBit\PositionService;
use App\Bot\Service\Stop\StopService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MoveStopsCommand extends Command
{
    protected static $defaultName = 'move-stops';

    public function __construct(
        private readonly StopRepository $stopRepository,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
            ->addArgument('priceToBeginFrom', InputArgument::REQUIRED, 'Price from which SL\'ses must be moved')
            ->addArgument('moveOverPrice', InputArgument::REQUIRED, 'Price above|under which must be placed');
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

            $priceToBeginFrom = $input->getArgument('priceToBeginFrom');
            $moveOverPrice = $input->getArgument('moveOverPrice');

            if (!(float)$priceToBeginFrom) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $priceToBeginFrom provided (%s)', $priceToBeginFrom),
                );
            }
            if (!(float)$moveOverPrice) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $moveOverPrice provided (%s)', $moveOverPrice),
                );
            }

            $stops = $this->stopRepository->findActive(
                side: $positionSide,
                qbModifier: function (QueryBuilder $qb) use ($positionSide, $priceToBeginFrom) {
                    $priceField = $qb->getRootAliases()[0] . '.price';

                    $qb
                        ->andWhere($priceField . ($positionSide === Side::Buy ? ' > :priceFrom' : ' < :priceFrom'))
                        ->setParameter(':priceFrom', $priceToBeginFrom)
                        ->orderBy($priceField, $positionSide === Side::Buy ? 'DESC' : 'ASC');
                }
            );

            foreach ($stops as $stop) {
                $stop->setPrice(
                    $positionSide === Side::Buy ? $moveOverPrice - 1 : $moveOverPrice + 1
                );
                $this->stopRepository->save($stop);
            }

//            $io->success(
//                \sprintf('Result transfer cost: %d', $cost),
//            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
