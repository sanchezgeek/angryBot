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
            $step = $this->paramFetcher->getFloatArgument('step');
            $priceRange = $this->getPriceRange();

            $context = ['uniqid' => $uniqueId = $this->uniqueIdGenerator->generateUniqueId('buy-grid')];
            if ($additionalContext = $this->getAdditionalBuyOrderContext()) {
                $context = array_merge($context, $additionalContext);
            }

            // @todo | calc real distance for each order in handler (or maybe in cmd, but for each BO)
            if ($stopDistance = $this->getOppositeOrdersDistanceOption($symbol)) {
                $context[BuyOrder::STOP_DISTANCE_CONTEXT] = $stopDistance;
            }

            foreach ($priceRange->byStepIterator($step) as $price) {
                $modifier = FloatHelper::modify($step / 7, 0.15);
                $rand = random_int(-$modifier, $modifier);

                $this->createBuyOrderHandler->handle(
                    new CreateBuyOrderEntryDto($symbol, $side, $volume, $price->add($rand)->value(), $context)
                );
            }

            $output = [
                sprintf(
                    './bin/console buy:edit%s --symbol=%s %s -aremove --fC="getContext(\'uniqid\')===\'%s\'"',
                    $this->io->isQuiet() ? ' --' . EditBuyOrdersCommand::WITHOUT_CONFIRMATION_OPTION : '', // to also quiet remove orders
                    $symbol->value,
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

                return new Position(
                    $this->getPositionSide(),
                    $symbol,
                    $indexPrice->value(),
                    $size = 0.001, // @todo | symbol
                    $size * $indexPrice->value(),
                    0,
                    10, // @todo | symbol
                    100,
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
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
