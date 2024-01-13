<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;

#[AsCommand(name: 'sl:move', description: 'Move position stops')]
class MoveStopsCommand extends Command
{
    private const OVER_FIRST_STOP = 'first_stop';
    private const OVER_ENTRY_PRICE = 'entry';
    private const OVER_SPECIFIED_PRICE = 'price';

    private const MODES = [
        self::OVER_FIRST_STOP,
        self::OVER_SPECIFIED_PRICE,
        self::OVER_ENTRY_PRICE,
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
            ->addOption('priceToBeginFrom', '-f', InputOption::VALUE_OPTIONAL, 'Price from which SL\'ses must be moved')
            ->addOption('mode', '-m', InputOption::VALUE_REQUIRED, 'Mode', self::OVER_SPECIFIED_PRICE)
            ->addOption('moveOverPrice', 'p', InputOption::VALUE_OPTIONAL, 'Price above|under which SL\'ses must be placed')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $priceToBeginFrom = $input->getOption('priceToBeginFrom');

            if (!$positionSide = Side::tryFrom($input->getArgument('position_side'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $input->getArgument('position_side')),
                );
            }

            $position = $this->positionService->getPosition(Symbol::BTCUSDT, $positionSide);
            if (!$position) {
                $io->info(\sprintf('OK (%d).', 0));
                return Command::SUCCESS;
            }

            $mode = $input->getOption('mode');
            if (!\in_array($mode, self::MODES)) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $mode provided (%s)', $mode),
                );
            }

            if ($mode === self::OVER_ENTRY_PRICE && !(float)$priceToBeginFrom) {
                $priceToBeginFrom = $positionSide->isShort() ? $position->entryPrice - 10 : $position->entryPrice + 10;
            }

            if (!($priceToBeginFrom = (float)$priceToBeginFrom)) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $priceToBeginFrom provided (%s)', $priceToBeginFrom),
                );
            }

            $moveOverPrice = $input->getOption('moveOverPrice');

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
                        ->andWhere($priceField . ($positionSide->isLong() ? ' > :priceFrom' : ' < :priceFrom'))
                        ->setParameter(':priceFrom', $priceToBeginFrom)
                        ->orderBy($priceField, $positionSide === Side::Buy ? 'DESC' : 'ASC');
                }
            );

            $price = null;

            if ($mode === self::OVER_ENTRY_PRICE) {
                $price = $positionSide->isShort() ? $position->entryPrice + 10 : $position->entryPrice - 10;
            }

            if ($mode === self::OVER_SPECIFIED_PRICE) {
                $price = $positionSide->isLong() ? $moveOverPrice - 1 : $moveOverPrice + 1;
            }

            if ($mode === self::OVER_FIRST_STOP) {
                $firstStop = $this->stopRepository->findFirstStopUnderPosition($position);

                if (!$firstStop) {

                    $guessPrice = $position->entryPrice;
                    $ticker = $this->exchangeService->ticker($position->symbol);

                    if ($ticker->isIndexAlreadyOverStop($position->side, $guessPrice)) {
                        $guessPrice = $ticker->indexPrice->sub(30)->value();
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
            }

            foreach ($stops as $stop) {
                $stop->setPrice($price)->clearOriginalPrice();

                $this->stopRepository->save($stop);
            }

            if (!$price) {
                throw new \LogicException('$price is undefined');
            }

            $io->info(\sprintf('OK (%d).', count($stops)));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
