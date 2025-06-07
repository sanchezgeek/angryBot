<?php

namespace App\Command\Buy;

use App\Application\UniqueIdGeneratorInterface;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OppositeOrdersDistanceAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalBuyOrderContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\PositionDependentCommand;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\FloatHelper;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_merge;
use function array_unshift;
use function random_int;
use function sprintf;
use function str_contains;

#[AsCommand(name: 'buy:grid')]
class CreateBuyGridCommand extends AbstractCommand implements PositionDependentCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand {
        getPosition as trait_getPosition;
    }
    use AdditionalBuyOrderContextAwareCommand;
    use PriceRangeAwareCommand;
    use OppositeOrdersDistanceAwareCommand;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->configurePriceRangeArgs()
            ->configureOppositeOrdersDistanceOption(alias: 's')
            ->addArgument('volume', InputArgument::REQUIRED, 'Buy volume')
            ->addArgument('step', InputArgument::REQUIRED, 'Step')
            ->configureBuyOrderAdditionalContexts()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $symbol = $this->getSymbol();
            $side = $this->getPositionSide();
            $volume = $this->paramFetcher->getFloatArgument('volume');
            $step = $this->getStepBasedOnPnl();
            $priceRange = $this->getPriceRange();

            $context = ['uniqid' => $uniqueId = $this->uniqueIdGenerator->generateUniqueId('buy-grid')];
            if ($additionalContext = $this->getAdditionalBuyOrderContext()) {
                $context = array_merge($context, $additionalContext);
            }

            // @todo | calc real distance for each order in handler (or maybe in cmd, but for each BO)
            if ($stopDistance = $this->getOppositeOrdersDistanceOption($symbol)) {
                $context[BuyOrder::OPPOSITE_ORDERS_DISTANCE_CONTEXT] = $stopDistance;
            }

            if (!$this->io->confirm(
                sprintf(
                    'You\'re about to buy %d orders in %s range with %s step. Sure?',
                    $priceRange->resultCountByStep($step),
                    $priceRange,
                    $step
                )
            )) {
                throw new Exception('OK.');
            }

            $result = null;
            $count = 0;
            $resultVolume = 0;
            foreach ($priceRange->byStepIterator($step, $side) as $price) {
                $count++;
                $modifier = FloatHelper::modify($step / 7, 0.15);
                $rand = random_int(-$modifier, $modifier);
                $orderPrice = $price->add($rand)->value();

                $exchangeOrder = ExchangeOrder::roundedToMin($symbol, $volume, $orderPrice);

                $qty = $exchangeOrder->getVolume();
                $result = $this->createBuyOrderHandler->handle(
                    new CreateBuyOrderEntryDto($symbol, $side, $qty, $orderPrice, $context)
                );
                $resultVolume += $qty;
            }

            $this->io->info(sprintf('%d orders has been created', $count));

            $createdWithVolume = $result->buyOrder->getVolume();
            if ($createdWithVolume !== $volume) {
                $this->io->info(sprintf('The Orders have been created with recalculated volume ~= %s', $createdWithVolume));
            }

            $this->io->info(sprintf('Total volume = %s', $resultVolume));

            $output = [EditBuyOrdersCommand::formatRemoveCmdByUniqueId($symbol, $side, $uniqueId, $this->io->isQuiet())];
            if (!$this->io->isQuiet()) {
                $this->io->success(sprintf('BuyOrders uniqueID: %s', $uniqueId));
                array_unshift($output, 'For delete them just run:');
            }

            $this->io->writeln($output, OutputInterface::VERBOSITY_QUIET);

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    protected function getStepBasedOnPnl(): float
    {
        $name = 'step';

        try {
            $pnlValue = $this->paramFetcher->getPercentArgument($name);
            $ticker = $this->exchangeService->ticker($this->getSymbol());
            $calculatedValue = PnlHelper::convertPnlPercentOnPriceToAbsDelta($pnlValue, $ticker->indexPrice);

            if (!$this->io->confirm(sprintf('You\'re about to use %.' . $this->getSymbol()->pricePrecision() . 'f as step', $calculatedValue))) {
                throw new Exception('OK.');
            }

            return $calculatedValue;
        } catch (InvalidArgumentException) {
            return $this->paramFetcher->getFloatArgument($name);
        }
    }

    /**
     * To create BuyOrders grid if relative percent passed with `from` and `to` options, but position not opened yet (ticker.indexPrice will be used)
     */
    protected function getPosition(bool $throwException = true): ?Position
    {
        try {
            return $this->trait_getPosition($throwException);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                $symbol = $this->getSymbol();
                $ticker = $this->exchangeService->ticker($symbol);
                $indexPrice = $ticker->indexPrice;

                $leverage = 100;
                return new Position(
                    $this->getPositionSide(),
                    $symbol,
                    $indexPrice->value(),
                    $size = $symbol->minOrderQty(),
                    ($value = $size * $indexPrice->value()),
                    0,
                    $value / $leverage,
                    $leverage,
                );
            }

            throw $e;
        }
    }

    public function __construct(
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        private readonly ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
