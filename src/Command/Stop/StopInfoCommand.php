<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Pnl;
use App\Bot\Domain\Position;
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

#[AsCommand(name: 'bot:sl:info', description: 'Move position stops')]
class StopInfoCommand extends Command
{
    private ?int $specifiedPeriods = 4;

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

        if (!$positionSide = Side::tryFrom($input->getArgument('position_side'))) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid $step provided (%s)', $input->getArgument('position_side')),
            );
        }

        $position = $this->positionService->getPosition($symbol, $positionSide);
        $ticker = $this->exchangeService->ticker($symbol);

        $specifiedPrice = null;
        if ($input->getOption('price') && !($specifiedPrice = (float)$input->getOption('price'))) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid $price provided (%s)', $specifiedPrice),
            );
        }

        if ($input->getOption('qnt') && !($this->specifiedPeriods = (int)$input->getOption('qnt'))) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid periods $qnt provided (%s)', $this->specifiedPeriods),
            );
        }

        try {
//            var_dump(
//                'size: ' . $position->size,
//                'entry: ' . $position->entryPrice,
//                'liq: ' . $position->liquidationPrice
//            );

            $ranges = [];
            if (isset($specifiedPrice)) {
                $ticker->isIndexAlreadyOverStop($positionSide, $specifiedPrice) && throw new \RuntimeException('Index price already over specified price');

                $ranges[] = [
                    'from' => $positionSide === Side::Sell ? $ticker->indexPrice : $specifiedPrice,
                    'to' => $positionSide === Side::Sell ? $specifiedPrice : $ticker->indexPrice,
                    'description' => null
                ];
            } else {
                $ranges[] = [
                    'from' => $positionSide === Side::Sell ? $ticker->indexPrice : $position->entryPrice + 100,
                    'to' => $positionSide === Side::Sell ? $position->entryPrice - 100 : $ticker->indexPrice,
                    'description' => '[to position entry price -200]'
                ];

                $proto = $positionSide === Side::Sell
                    ? ['from' => $position->entryPrice - 100, 'to' => $position->entryPrice]
                    : ['from' => $position->entryPrice, 'to' => $position->entryPrice + 100]
                ;

                for ($i=0; $i<$this->specifiedPeriods; $i++) {
                    $proto['description'] = \sprintf(
                        '[%s$100 %s]',
                        $positionSide === Side::Sell ? '+' : '-',
                        $positionSide === Side::Sell ? 'up' : 'down',
                    );

                    $ranges[] = $proto;

                    if ($positionSide === Side::Sell) {
                        $proto['from'] += 100; $proto['to'] += 100;
                    } else {
                        $proto['from'] -= 100; $proto['to'] -= 100;
                    }
                }
            }

            $totalVolume = 0;
            $totalPnl = 0;
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

                [$volume, $pnl] = $this->sum($position, ...$stops);

                $io->note(
                    \sprintf(
                        '[%.0f - %.0f] | %.3f btc | %.1f%% (%s) %s',
                        $from,
                        $to,
                        $volume,
                        \round($volume / $position->size, 3) * 100,
                        new Pnl($pnl, 'USDT'),
                        $description ?? ''
                    ),
                );

                $totalVolume += $volume;
                $totalPnl += $pnl;
            }

            $io->note([
                \sprintf('total stops volume: %.3f btc', $totalVolume),
                \sprintf('total PNL: %s', new Pnl($totalPnl)),
                \sprintf('volume stopped: %.2f%%', ($totalVolume / $position->size) * 100),
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return float[]
     */
    private function sum(Position $position, Stop... $stops): array
    {
        $sum = 0;
        $pnl = 0;
        foreach ($stops as $stop) {
            $sum += $stop->getVolume();
            $pnl += $stop->getPnlUsd($position);
        }

        return [$sum, $pnl];
    }
}
