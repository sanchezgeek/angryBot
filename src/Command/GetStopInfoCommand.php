<?php

namespace App\Command;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'bot:stop-info', description: 'Move position stops')]
class GetStopInfoCommand extends Command
{
    public function __construct(
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
            ->addOption('price', 'p', InputOption::VALUE_OPTIONAL, 'Price end calc info')
            ->addOption('qnt', 'r', InputOption::VALUE_OPTIONAL, 'Periods count')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = Symbol::BTCUSDT;

        try {
            if (!$positionSide = Side::tryFrom($input->getArgument('position_side'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $input->getArgument('position_side')),
                );
            }

            if ($input->getOption('price') && !($price = (float)$input->getOption('price'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $price provided (%s)', $price),
                );
            }

            if ($input->getOption('qnt') && !($periods = (float)$input->getOption('qnt'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid periods $qnt provided (%s)', $periods),
                );
            }

            $periods = $periods ?? 4;

            $ticker = $this->exchangeService->getTicker($symbol);
            $position = $this->positionService->getPosition($symbol, $positionSide);
            var_dump('entry: ' . $position->entryPrice . '; liq: ' . $position->liquidationPrice);

            $ranges = [];
            if (isset($price)) {
                $ticker->isIndexAlreadyOverStop($positionSide, $price) && throw new \RuntimeException('Index price already over specified price');

                $ranges[] = [
                    'from' => $positionSide === Side::Sell ? $ticker->indexPrice : $price,
                    'to' => $positionSide === Side::Sell ? $price : $ticker->indexPrice,
                    'description' => null
                ];
            } else {
                $ranges[] = [
                    'from' => $positionSide === Side::Sell ? $ticker->indexPrice : $position->entryPrice,
                    'to' => $positionSide === Side::Sell ? $position->entryPrice : $ticker->indexPrice,
                    'description' => '[to position entry price]'
                ];

                $proto = $positionSide === Side::Sell
                    ? ['from' => $position->entryPrice - 100, 'to' => $position->entryPrice]
                    : ['from' => $position->entryPrice, 'to' => $position->entryPrice + 100]
                ;

                for ($i=0; $i<$periods; $i++) {
                    if ($positionSide === Side::Sell) {
                        $proto['from'] += 100; $proto['to'] += 100;
                    } else {
                        $proto['from'] -= 100; $proto['to'] -= 100;
                    }

                    $proto['description'] = \sprintf(
                        '[%s$100 %s]',
                        $positionSide === Side::Sell ? '+' : '-',
                        $positionSide === Side::Sell ? 'up' : 'down',
                    );

                    $ranges[] = $proto;
                }
            }

            foreach ($ranges as ['from' => $from, 'to' => $to, 'description' => $description]) {
                $stops = $this->stopRepository->findActive(
                    side: $positionSide,
                    qbModifier: function (QueryBuilder $qb) use ($positionSide, $from, $to) {
                        $priceField = $qb->getRootAliases()[0] . '.price';

                        $qb
                            ->andWhere(\sprintf('%s between :from and :to', $priceField))
                            ->setParameter(':from', $from)
                            ->setParameter(':to', $to)
                        ;
                    }
                );

                $sum = $this->sumVolume(...$stops);

                $io->info(
                    \sprintf('from %.0f to %.0f: %.3f btc %s', $from, $to, $sum, $description ?? ''),
                );
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function info(SymfonyStyle $io, $range): void
    {
        $io->info(
            \sprintf('from %.0f to %.0f: %.3f btc %s', $from, $to, $sum, $description ?? ''),
        );
    }

    private function sumVolume(Stop... $stops): float
    {
        $sum = 0;
        foreach ($stops as $stop) {
            $sum += $stop->getVolume();
        }

        return $sum;
    }
}
