<?php

namespace App\Buy\UI\Command;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'buy:reset-active')]
class ResetActiveFlagCommand extends AbstractCommand
{
    use PositionAwareCommand;
    use SymbolAwareCommand;
    use ConsoleInputAwareCommand;

    private const ALLOWED_PNL_DELTA_OPTION = 'allowed-pnl-delta';
    private const ALLOWED_PNL_DELTA_DEFAULT = '25%';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->configurePositionArgs(InputArgument::OPTIONAL)
            ->addOption(self::ALLOWED_PNL_DELTA_OPTION, null, InputOption::VALUE_OPTIONAL, 'PNL percent threshold to keep orders active even if order price laying before ticker', self::ALLOWED_PNL_DELTA_DEFAULT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbols = $this->symbolIsSpecified() ? $this->getSymbols() : [];
        $side = $this->getPositionSide(false);
        $allowedPnl = $this->paramFetcher->percentOption(self::ALLOWED_PNL_DELTA_OPTION);

        $modified = [];
        foreach ($symbols as $symbol) {
            $ticker = $this->exchangeService->ticker($symbol);
            $orders = $this->repository->findActiveForPush($symbol, $side);

            foreach ($orders as $order) {
                $orderPrice = $order->getPrice();
                if (!$ticker->isIndexAlreadyOverBuyOrder($side, $orderPrice)) {
                    continue;
                }

                $orderPriceDistancePercentPnl = PnlHelper::convertAbsDeltaToPnlPercentOnPrice($ticker->indexPrice->deltaWith($orderPrice), $ticker->indexPrice);
                if ($orderPriceDistancePercentPnl->value() > $allowedPnl) {
                    $this->repository->save($order->setIdle());
                    $modified[] = $order;
                }
            }
        }

        $this->io->info(sprintf('Orders state set to idle: %s', $modified ? implode(',', OutputHelper::ordersDebug($modified)) : 'none'));

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ByBitLinearExchangeService $exchangeService,
        PositionServiceInterface $positionService,
        private readonly BuyOrderRepository $repository,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
