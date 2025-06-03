<?php

namespace App\Command\Position;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Position\ValueObject\Side;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:opened-with-orders')]
class EchoOpenedPositionsWithOrdersCommand extends AbstractCommand
{
    use PositionAwareCommand;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbols = $this->positionService->getOpenedPositionsSymbols();

        /** @var array<array{symbol: SymbolInterface, side: Side}> $result */
        $result = [];
        foreach ($symbols as $symbol) {
            $result = array_merge($result, $this->getPositionsWithOrders($symbol));
        }

        foreach ($result as $item) {
            echo sprintf("%s %s\n", $item['symbol']->shortName(), $item['side']->value);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<array{symbol: SymbolInterface, side: Side}>
     */
    private function getPositionsWithOrders(SymbolInterface $symbol): array
    {
        $positions = $this->positionService->getPositions($symbol);

        $result = [];
        foreach ($positions as $position) {
            $positionSide = $position->side;
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

        return $result;
    }

    public function __construct(
        PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderRepository $buyOrderRepository,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
