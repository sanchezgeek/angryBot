<?php

namespace App\Command\Buy;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalBuyOrderContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_merge;
use function random_int;
use function round;

#[AsCommand(name: 'buy:grid')]
class CreateBuyGridCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
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

            $context = ['uniqid' => $uniqueId = uniqid('inc-create', true)];
            if ($additionalContext = $this->getAdditionalBuyOrderContext()) {
                $context = array_merge($context, $additionalContext);
            }

            foreach ($priceRange->byStepIterator($step) as $price) {
                $rand = round(random_int(-8, 9) * 0.8, 2);

                $this->createBuyOrderHandler->handle(
                    new CreateBuyOrderEntryDto($side, $volume, $price->sub($rand)->value(), $context)
                );
            }

            $this->io->success(sprintf('BuyOrders uniqueID: %s', $uniqueId));
            $this->io->writeln(
                sprintf('For delete them just run:' . PHP_EOL . './bin/console buy:edit %s -aremove \ --filterCallbacks="getContext(\'uniqid\')===\'%s\'"', $side->value, $uniqueId)
            );

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    public function __construct(
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
