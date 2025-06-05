<?php

namespace App\Command\Position;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:opened-with-orders')]
class EchoOpenedPositionsWithOrdersCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var array<array{symbol: SymbolInterface, side: Side}> $result */
        $result = [];
        foreach ($this->positionService->getAllPositions() as $symbolPositions) {
            foreach ($symbolPositions as $position) {
                $positionSide = $position->side;
                $symbol = $position->symbol;
                $pushedStops = $this->exchangeService->activeConditionalOrders($symbol);

                $stops = array_filter(
                    $this->stopRepository->findAllByPositionSide($symbol, $positionSide),
                    static fn(Stop $stop):bool => !$stop->isOrderPushedToExchange() || isset($pushedStops[$stop->getExchangeOrderId()])
                );
                $buyOrders = $this->buyOrderRepository->findActive($symbol, $positionSide);

                if (!$stops && !$buyOrders) {
                    continue;
                }

                $result[] = ['symbol' => $symbol, 'side' => $positionSide];
            }
        }

        foreach ($result as $item) {
            echo sprintf("%s %s\n", $item['symbol']->name(), $item['side']->value);
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ByBitLinearPositionService $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderRepository $buyOrderRepository,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
