<?php

namespace App\Command;

use App\Delivery\Application\Service\DeliveryCost\DeliveryCostCalculator;
use App\Delivery\Application\Service\DeliveryCost\DeliveryPriceRange;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class CalculateCommand extends Command
{
    protected static $defaultName = 'calculate';

    protected static $defaultDescription = 'Calculates transfer price upon specified distance price ranges.';

    protected function configure(): void
    {
        $this
            ->addArgument('distance', InputArgument::REQUIRED, 'Transfer distance.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        $io = new SymfonyStyle($input, $output);

        try {
            $distance = $input->getArgument('distance');
            if (!(int)$distance) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid distance provided (%s)', $distance)
                );
            }

            /** @var DeliveryPriceRange[] $ranges */
            $ranges = [];
            do {
                $start = count($ranges) ? end($ranges)->getEnd() : 0;
                $end = $helper->ask($input, $output, new Question(
                    'Please enter range end (or press ENTER if there is no more ranges): '
                ));

                $price = $helper->ask($input, $output, new Question(
                    \sprintf('Please enter range %s..%s price: ', $start, $end ?: '∞')
                ));

                $ranges[] = new DeliveryPriceRange($start, $end, $price);
            } while ($end !== null);

            $calculator = new DeliveryCostCalculator();

            $cost = $calculator->calculate((int)$distance, ...$ranges);

            $io->success(
                \sprintf('Result transfer cost: %d', $cost)
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
