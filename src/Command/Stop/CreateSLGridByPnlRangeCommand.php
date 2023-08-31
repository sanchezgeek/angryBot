<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\OrdersGrid;
use App\Domain\Order\Order;
use App\Domain\Position\ValueObject\Side;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;
use function in_array;
use function is_numeric;
use function iterator_to_array;
use function sprintf;
use function str_ends_with;
use function substr;
use function uniqid;

#[AsCommand(name: 'sl:grid:by-pnl')]
class CreateSLGridByPnlRangeCommand extends Command
{
    private const DEFAULT_TRIGGER_DELTA = 17;

    private const BY_PRICE_STEP = 'by_step';
    private const BY_ORDERS_QNT = 'by_qnt';

    private const MODES = [
        self::BY_PRICE_STEP,
        self::BY_ORDERS_QNT,
    ];

    private InputInterface $input;
    private SymfonyStyle $io;

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
            ->addArgument('forVolume', InputArgument::REQUIRED, 'Volume value || $ of position size')
            ->addOption('fromPnl', '-f', InputOption::VALUE_REQUIRED, 'fromPnl (%)')
            ->addOption('toPnl', '-t', InputOption::VALUE_REQUIRED, 'toPnl (%)')
            ->addOption('mode', '-m', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::MODES) . ')', self::BY_ORDERS_QNT)
            ->addOption('ordersQnt', '-c', InputOption::VALUE_OPTIONAL, 'Grid orders count')
            ->addOption('priceStep', '-s', InputOption::VALUE_OPTIONAL, 'Grid PriceStep')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $this->input = $input; $this->io = $io;

        // @todo | tD ?
        // @todo | For price value ?
        $fromPnl = $this->getPercentParam($input->getOption('fromPnl'), 'fromPnl');
        $toPnl = $this->getPercentParam($input->getOption('toPnl'), 'toPnl');
        $forVolume = $this->getForVolumeParam();
        $mode = $this->getModeParam();
        $position = $this->getPosition();

        $context = ['uniqid' => $uniqueId = uniqid('inc-create', true)];

        $stopsGrid = OrdersGrid::byPositionPnlRange($position, $fromPnl, $toPnl);

        if ($mode === self::BY_ORDERS_QNT) {
            if (($qnt = $input->getOption('ordersQnt')) === null) {
                throw new LogicException(sprintf('In \'%s\' mode param "%s" is required', $mode, 'ordersQnt'));
            }
            $qnt = $this->getIntParam($qnt, 'ordersQnt');

            /** @var Order[] $orders */
            $orders = iterator_to_array($stopsGrid->ordersByQnt($forVolume, $qnt));
            if (!$this->io->confirm(sprintf('Count: %d, ~Volume: %.3f. Are you sure?', $qnt, $orders[0]->volume()))) {
                return Command::FAILURE;
            }
        } else {
            throw new InvalidArgumentException('"by_step" mode not implemented yet.');
        }

        $alreadyStopped = 0;
        $stops = $this->stopRepository->findActive($position->side);
        foreach ($stops as $stop) {
            $alreadyStopped += $stop->getVolume();
        }

        if ($position->size <= $alreadyStopped) {
            if (!$io->confirm(
                sprintf('All position volume already under SL\'ses. Want to continue? ')
            )) {
                return Command::FAILURE;
            }
        }

        foreach ($orders as $order) {
            $this->stopService->create($position->side, $order->price()->value(), $order->volume(), self::DEFAULT_TRIGGER_DELTA, $context);
        }

        $io->success(
            sprintf('Stops grid created. uniqueID: %s', $uniqueId)
        );

        return Command::SUCCESS;
    }

    private function getModeParam(): string
    {
        $mode = $this->input->getOption('mode');
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $mode provided (%s)', $mode),
            );
        }

        return $mode;
    }

    private function getIntParam(string $value, string $name): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(
                sprintf('Invalid \'%s\' INT param provided ("%s" given).', $name, $value)
            );
        }

        return (int)$value;
    }

    private function getPercentParam(string $value, string $name): int
    {
        if (
            !str_ends_with($value, '%')
            || (!is_numeric(substr($value, 0, -1)))
        ) {
            throw new InvalidArgumentException(
                sprintf('Invalid \'%s\' PERCENT param provided ("%s" given).', $name, $value)
            );
        }

        return (int)substr($value, 0, -1);
    }

    private function getFloatParam(string $value, string $name): float
    {
        if (!($floatValue = (float)$value)) {
            throw new InvalidArgumentException(
                sprintf('Invalid \'%s\' FLOAT param provided ("%s" given).', $name, $value)
            );
        }

        return $floatValue;
    }

    private function getForVolumeParam(): float
    {
        $position = $this->getPosition();

        try {
            $positionSizePart = $this->getPercentParam($this->input->getArgument('forVolume'), 'forVolume');
            $forVolume = $position->getVolumePart($positionSizePart);
        } catch (InvalidArgumentException) {
            $forVolume = $this->getFloatParam($this->input->getArgument('forVolume'), 'forVolume');
        }

        if ($forVolume >= $position->size) {
            throw new LogicException('$forVolume is greater than whole position size');
        }

        if (($forVolume > $position->size / 3) && !$this->io->confirm(sprintf('Are you sure?'))) {
            return Command::FAILURE;
        }

        return $forVolume;
    }

    private function getPosition(): Position
    {
        if (!$positionSide = Side::tryFrom($this->input->getArgument('position_side'))) {
            throw new InvalidArgumentException(
                sprintf('Invalid $step provided (%s)', $this->input->getArgument('position_side')),
            );
        }

        if (!$position = $this->positionService->getPosition(Symbol::BTCUSDT, $positionSide)) {
            throw new RuntimeException(sprintf('Position on %s side not found', $positionSide->title()));
        }

        return $position;
    }

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
