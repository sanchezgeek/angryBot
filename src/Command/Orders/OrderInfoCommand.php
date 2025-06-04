<?php

namespace App\Command\Orders;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Helper\OutputHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'o:info')]
class OrderInfoCommand extends AbstractCommand implements SymbolDependentCommand
{
    use SymbolAwareCommand;

    private const IDS_ARG = 'ids';
    private const REMOVE_OPTION = 'remove';
    private const EDIT_OPTION = 'edit';
    public const EDIT_CALLBACK_OPTION = 'eC';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addArgument(self::IDS_ARG, InputArgument::REQUIRED, 'Orders IDs (prefixed with `b.` [buy] or `s.` [sell]) separated by comma')
            ->addOption(self::REMOVE_OPTION, 'r', InputOption::VALUE_NEGATABLE, 'Remove?')
            ->addOption(self::EDIT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Edit?')
            ->addOption(self::EDIT_CALLBACK_OPTION, null, InputOption::VALUE_REQUIRED, 'Edit callback')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
//        while ($c = fread(STDIN, 16)) {$c = preg_replace('/[^[:print:]\n]/u', '', mb_convert_encoding($c, 'UTF-8', 'UTF-8')); if ($c === "[A") echo "UP"; if ($c === "[B") echo "DOWN"; if ($c === "[C")echo "RIGHT"; if ($c === "[D") echo "LEFT";}
        $rawIds = $this->paramFetcher->getStringArgument(self::IDS_ARG);
        $rawIds = explode(',', $rawIds);

        $orderType = null;
        foreach ($rawIds as $rawId) {
            assert(preg_match('/^([bs])\.\d+$/', $rawId), sprintf('Invalid order definition ("%s") provided (good example: b.123 or s.123)', $rawId));
            $rawId = explode('.', $rawId);

            assert(!$orderType || $rawId[0] === $orderType, 'All provided orders must be of one type (buy / stop)');
            $orderType = $rawId[0];
        }
        $repository = $orderType === 'b' ? $this->buyOrderRepository : $this->stopRepository;
        $orderType = $orderType === 'b' ? OrderType::Add : OrderType::Stop;

        $globalEC = $this->paramFetcher->getStringOption(self::EDIT_CALLBACK_OPTION, false);

        $action = match (true) {
            $this->paramFetcher->getBoolOption(self::REMOVE_OPTION) => 'r',
            $globalEC || $this->paramFetcher->getBoolOption(self::EDIT_OPTION) => 'e',
            default => $this->io->ask('Select action: "e" - edit, "r" - remove'),
        };

        $showInfo = true;
        if ($action === 'e' && $globalEC) {
            $showInfo = false;
        }

        foreach ($rawIds as $rawId) {
            $editCallback = null;
            $id = explode('.', $rawId);
            $order = $repository->find($id[1]);
            assert($order !== null, sprintf('Cannot find %s order with id=%d', $orderType->title(), $id[1]));

            $showInfo && OutputHelper::print($order->info());

            if ($action === 'e') {
                $editCallback = $globalEC ?? $this->io->ask(sprintf('Set callback for "%s". E.g. `setPrice(100500)`, `setVolume(0.005), ...`', $rawId));
                eval('$order->' . $editCallback . ';');
                $repository->save($order);
            } elseif ($action === 'r') {
                if ($this->io->confirm('Are you sure you want to remove this order?', false)) {
                    $closeActiveCondOrder = false;
                    $activeCondOrder = null;
                    if ($order instanceof Stop && ($exchangeOrderId = $order->getExchangeOrderId())) {
                        $pushedStops = $this->exchangeService->activeConditionalOrders($this->getSymbol());
                        if (isset($pushedStops[$exchangeOrderId])) {
                            $activeCondOrder = $pushedStops[$exchangeOrderId];
                            $closeActiveCondOrder = $this->io->confirm(sprintf('Order pushed to exchange ("%s"). Close?', $exchangeOrderId));
                        }
                    }

                    if ($closeActiveCondOrder) {
                        $this->exchangeService->closeActiveConditionalOrder($activeCondOrder);
                    }

                    $repository->remove($order);
                }
            }
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly ExchangeServiceInterface $exchangeService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
