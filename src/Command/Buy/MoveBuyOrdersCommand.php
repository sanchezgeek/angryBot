<?php

namespace App\Command\Buy;

use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Command\AbstractCommand;
use App\Domain\Position\ValueObject\Side;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'buy:move', description: 'Move position buy-orders')]
class MoveBuyOrdersCommand extends AbstractCommand
{
    public function __construct(
        private readonly BuyOrderRepository $buyOrderRepository,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('priceToBeginBefore', InputArgument::REQUIRED, 'Price from which BuyOrders must be moved')
            ->addArgument('moveOverPrice', InputArgument::REQUIRED, 'Price above|under which BuyOrders must be placed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if (!$positionSide = Side::tryFrom($input->getArgument('position_side'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $input->getArgument('position_side')),
                );
            }

            $priceToBeginBefore = $input->getArgument('priceToBeginBefore');
            $moveOverPrice = $input->getArgument('moveOverPrice');

            if (!(float)$priceToBeginBefore) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid priceToBeginBefore provided (%s)', $priceToBeginBefore),
                );
            }
            if (!(float)$moveOverPrice) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $moveOverPrice provided (%s)', $moveOverPrice),
                );
            }

            $orders = $this->buyOrderRepository->findActive(
                side: $positionSide,
                qbModifier: function (QueryBuilder $qb) use ($positionSide, $priceToBeginBefore) {
                    $priceField = $qb->getRootAliases()[0] . '.price';

                    $qb
                        ->andWhere($priceField . ($positionSide === Side::Buy ? ' < :priceFrom' : ' > :priceFrom'))
                        ->setParameter(':priceFrom', $priceToBeginBefore)
                        ->orderBy($priceField, $positionSide === Side::Buy ? 'ASC' : 'DESC');
                }
            );

            foreach ($orders as $order) {
                $order->setPrice(
                    $positionSide === Side::Buy ? $moveOverPrice + 1 : $moveOverPrice - 1
                );
                $this->buyOrderRepository->save($order);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
