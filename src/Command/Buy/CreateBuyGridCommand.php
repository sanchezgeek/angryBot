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
use App\Command\Mixin\OrderContext\AdditionalBuyOrderContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Throwable;

use function array_merge;
use function array_unshift;
use function random_int;
use function sprintf;
use function str_contains;

#[AsCommand(name: 'buy:grid')]
class CreateBuyGridCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand {
        getPosition as trait_getPosition;
    }
    use AdditionalBuyOrderContextAwareCommand;
    use PriceRangeAwareCommand;

    const STOP_DISTANCE_OPTION = 'stopDistance';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->configurePriceRangeArgs()
            ->addArgument('volume', InputArgument::REQUIRED, 'Buy volume')
            ->addArgument('step', InputArgument::REQUIRED, 'Step')
            ->addOption(self::STOP_DISTANCE_OPTION, 'd', InputOption::VALUE_REQUIRED, 'SL distance (abs. or %)')
            ->configureBuyOrderAdditionalContexts()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $side = $this->getPositionSide();
            $volume = $this->paramFetcher->getFloatArgument('volume');
            $step = $this->paramFetcher->getIntArgument('step');
            $priceRange = $this->getPriceRange();

            $context = ['uniqid' => $uniqueId = $this->uniqueIdGenerator->generateUniqueId('buy-grid')];
            if ($additionalContext = $this->getAdditionalBuyOrderContext()) {
                $context = array_merge($context, $additionalContext);
            }

            // @todo | calc real distance for each order in handler (or maybe in cmd, but for each BO)
            if ($stopDistance = $this->getStopDistanceOption()) {
                $context[BuyOrder::STOP_DISTANCE_CONTEXT] = $stopDistance;
            }

            foreach ($priceRange->byStepIterator($step) as $price) {
                $modifier = FloatHelper::modify($step / 7, 0.15);
                $rand = random_int(-$modifier, $modifier);

                $this->createBuyOrderHandler->handle(
                    new CreateBuyOrderEntryDto($side, $volume, $price->add($rand)->value(), $context)
                );
            }

            $output = [
                sprintf(
                    './bin/console buy:edit%s %s -aremove --fC="getContext(\'uniqid\')===\'%s\'"',
                    $this->io->isQuiet() ? ' --' . EditBuyOrdersCommand::WITHOUT_CONFIRMATION_OPTION : '', // to also quiet remove orders
                    $side->value,
                    $uniqueId
                )
            ];

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

    /**
     * @throws Throwable
     */
    protected function getStopDistanceOption(): ?float
    {
        $name = self::STOP_DISTANCE_OPTION;

        try {
            $pnlValue = $this->paramFetcher->requiredPercentOption($name);

            try {
                $basedOnPrice = $this->exchangeService->ticker($this->getSymbol())->indexPrice;
            } catch (\Throwable $e) {
                if (!$this->io->confirm(sprintf('Got "%s" error while do `ticker` request. Want to use price from specified price range?', $e->getMessage()), true)) {
                    throw $e;
                }
                $basedOnPrice = $this->getPriceRange()->getMiddlePrice();
            }

            // @todo | can calc with existed helpers?
            $pp100 = $basedOnPrice->value() / 100;

            return FloatHelper::round((new Percent($pnlValue, false))->of($pp100));
        } catch (InvalidArgumentException) {
            return $this->paramFetcher->floatOption($name);
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
                return new Position($this->getPositionSide(), $symbol, $indexPrice->value(), $size = 0.001, $size * $indexPrice->value(), 0, 10, 10, 100);
            }

            throw $e;
        }
    }

    public function __construct(
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        private readonly ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
