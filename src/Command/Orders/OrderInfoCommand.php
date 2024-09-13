<?php

namespace App\Command\Orders;

use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Helper\OutputHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'o:info')]
class OrderInfoCommand extends AbstractCommand
{
    private const ID_ARG = 'id';
    private const REMOVE_OPTION = 'remove';
    private const EDIT_OPTION = 'edit';

    /** `edit`-action options */
    public const EDIT_CALLBACK_OPTION = 'eC';

    protected function configure(): void
    {
        $this
            ->addArgument(self::ID_ARG, InputArgument::REQUIRED, 'Order ID (prefixed with `b.` [buy] or `s.` [sell])')
            ->addOption(self::REMOVE_OPTION, 'r', InputOption::VALUE_NEGATABLE, 'Remove?')
            ->addOption(self::EDIT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Edit?')
            ->addOption(self::EDIT_CALLBACK_OPTION, null, InputOption::VALUE_REQUIRED, 'Edit callback')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
//        while ($c = fread(STDIN, 16)) {$c = preg_replace('/[^[:print:]\n]/u', '', mb_convert_encoding($c, 'UTF-8', 'UTF-8')); if ($c === "[A") echo "UP"; if ($c === "[B") echo "DOWN"; if ($c === "[C")echo "RIGHT"; if ($c === "[D") echo "LEFT";}
        $rawId = $this->paramFetcher->getStringArgument(self::ID_ARG);
        if (!preg_match('/^([bs])\.\d+$/', $rawId)) {
            throw new \InvalidArgumentException('Invalid order definition provided (good example: b.123 or s.123)');
        }

        $rawId = explode('.', $rawId);
        $repository = $rawId[0] === 'b' ? $this->buyOrderRepository : $this->stopRepository;
        $id = $rawId[1];

        if (!$order = $repository->find($id)) {
            throw new \Exception('Order not found');
        }

        if ($this->paramFetcher->getBoolOption(self::REMOVE_OPTION)) {
            $action = 'r';
        } elseif ($this->paramFetcher->getBoolOption(self::EDIT_OPTION)) {
            $action = 'e';
        } else {
            $action = $this->io->ask('Select action: "e" - edit, "r" - remove');
        }

        $showInfo = true;
        if ($action === 'e' && ($editCallback = $this->paramFetcher->getStringOption(self::EDIT_CALLBACK_OPTION, false))) {
            $showInfo = false;
        }
        $showInfo && OutputHelper::print($order->info());

        if ($action === 'e') {
            $editCallback = $editCallback ?? $this->io->ask('Set callback. E.g. `setPrice(100500)`, `setVolume(0.005), ...`');
            eval('$order->' . $editCallback . ';');
            $repository->save($order);
        } elseif ($action === 'r') {
            if ($this->io->confirm('Are you sure you want to remove this order?', false)) {
                $repository->remove($order);
            }
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderRepository $buyOrderRepository,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
