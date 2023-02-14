<?php

namespace App\Command;

use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Infrastructure\ByBit\PositionService;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MoveStopsCommand extends Command
{
    private const OVER_FIRST_STOP = 'first_stop';
    private const OVER_SPECIFIED_PRICE = 'price';

    private const MODES = [
        self::OVER_FIRST_STOP,
        self::OVER_SPECIFIED_PRICE,
    ];

    protected static $defaultName = 'move-stops';

    public function __construct(
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
            ->addArgument('priceToBeginFrom', InputArgument::REQUIRED, 'Price from which SL\'ses must be moved')
            ->addOption('mode', '-m', InputOption::VALUE_REQUIRED, 'Mode', self::OVER_FIRST_STOP)
            ->addOption('moveOverPrice', 'p', InputOption::VALUE_OPTIONAL, 'Price above|under which must be placed')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $priceToBeginFrom = $input->getArgument('priceToBeginFrom');

            if (!$positionSide = Side::tryFrom($input->getArgument('position_side'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $input->getArgument('position_side')),
                );
            }

            if (!(float)$priceToBeginFrom) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $priceToBeginFrom provided (%s)', $priceToBeginFrom),
                );
            }

            $mode = $input->getOption('mode');
            $moveOverPrice = $input->getOption('moveOverPrice');

            if (!\in_array($mode, self::MODES)) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $mode provided (%s)', $mode),
                );
            }

            if ($mode === self::OVER_SPECIFIED_PRICE && !(float)$moveOverPrice) {
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


            if ($mode === self::OVER_SPECIFIED_PRICE) {
                foreach ($stops as $stop) {
                    $stop->setPrice(
                        $positionSide === Side::Buy ? $moveOverPrice - 1 : $moveOverPrice + 1
                    );
                    $this->stopRepository->save($stop);
                }
            }

            if ($mode === self::OVER_FIRST_STOP) {
                $position = $this->positionService->getOpenedPositionInfo(Symbol::BTCUSDT, $positionSide);
                if (!$position) {
                    throw new \LogicException('Position not found');
                }

                $firstStop = $this->stopRepository->findFirstStopUnderPosition($position);

                if (!$firstStop) {

                    $guessPrice = $position->entryPrice;
                    $ticker = $this->positionService->getTicker($position->symbol);

                    if ($ticker->isIndexAlreadyOverStop($position->side, $guessPrice)) {
                        $guessPrice = $ticker->indexPrice - 30;
                    }

                    throw new \LogicException(
                        \sprintf(
                            'Cannot find stops under position. Manual: ./bin/console move-stops %s %s -m price -p %s',
                            $positionSide->value,
                            $priceToBeginFrom,
                            $guessPrice
                        )
                    );
                }

                $price = $positionSide === Side::Buy ? $firstStop->getPrice() - 1 : $firstStop->getPrice() + 1;

                foreach ($stops as $stop) {
                    $stop->setPrice(
                        $price
                    );
                    $this->stopRepository->save($stop);
                }

                $io->info(
                    \sprintf('Stops moved to %s', $price)
                );
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
