<?php

namespace App\Command\Buy;

use App\Application\UniqueIdGeneratorInterface;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalBuyOrderContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_merge;
use function array_unshift;
use function random_int;
use function round;
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

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->configurePriceRangeArgs()
            ->addArgument('volume', InputArgument::REQUIRED, 'Buy volume')
            ->addArgument('step', InputArgument::REQUIRED, 'Step')
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

            foreach ($priceRange->byStepIterator($step) as $price) {
                $rand = round(random_int(-8, 9) * 0.8, 2);

                $this->createBuyOrderHandler->handle(
                    new CreateBuyOrderEntryDto($side, $volume, $price->sub($rand)->value(), $context)
                );
            }

            $output = [
                sprintf(
                    './bin/console buy:edit%s %s -aremove --filterCallbacks="getContext(\'uniqid\')===\'%s\'"',
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
                return new Position($this->getPositionSide(), $symbol, $indexPrice->value(), $size = 0.001, $size * $indexPrice->value(), 0, 10, 100);
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
