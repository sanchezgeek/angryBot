<?php

namespace App\Command\Buy;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\BuyOrderService;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalBuyOrderContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_merge;
use function random_int;
use function round;

#[AsCommand(name: 'buy:grid')]
class CreateBuyGridCommand extends Command
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
        $io = new SymfonyStyle($input, $output); $this->withInput($input);

        try {
            $triggerDelta = 1;
            $positionSide = $this->getPositionSide();
            $volume = $this->paramFetcher->getFloatArgument('volume');
            $step = $this->paramFetcher->getIntArgument('step');
            $priceRange = $this->getPriceRange();

            $context = ['uniqid' => $uniqueId = \uniqid('inc-create', true)];
            if ($additionalContext = $this->getAdditionalBuyOrderContext()) {
                $context = array_merge($context, $additionalContext);
            }

            foreach ($priceRange->byStepIterator($step) as $price) {
                $rand = round(random_int(-7, 8) * 0.4, 2);
                $price = $price->sub($rand);

                $this->buyOrderService->create($positionSide, $price->value(), $volume, $triggerDelta, $context);
            }

            $io->success(\sprintf('BuyOrders uniqueID: %s', $uniqueId));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    public function __construct(
        private readonly BuyOrderService $buyOrderService,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
