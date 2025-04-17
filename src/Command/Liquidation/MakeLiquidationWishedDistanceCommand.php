<?php

namespace App\Command\Liquidation;

use App\Application\UniqueIdGeneratorInterface;
use App\Application\UseCase\Position\CalcPositionVolumeBasedOnLiquidationPrice\CalcPositionVolumeBasedOnLiquidationPriceEntryDto;
use App\Application\UseCase\Position\CalcPositionVolumeBasedOnLiquidationPrice\CalcPositionVolumeBasedOnLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OppositeOrdersDistanceAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\Stop\CreateStopsGridCommand;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Price;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'liq:wish-distance')]
class MakeLiquidationWishedDistanceCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use PriceRangeAwareCommand;
    use AdditionalStopContextAwareCommand;
    use OppositeOrdersDistanceAwareCommand;

    public const WISHED_LIQUIDATION_DISTANCE = 'wishedLiquidationDistance';

    public const WITHOUT_CONFIRMATION_OPTION = 'y';
    public const AS_STOP_OPTION = 'as-stop';

    private array $addedArguments = [];

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument(self::WISHED_LIQUIDATION_DISTANCE, InputArgument::REQUIRED)
            ->addOption(self::WITHOUT_CONFIRMATION_OPTION, null, InputOption::VALUE_NEGATABLE, 'Without confirm')
            ->addOption(self::AS_STOP_OPTION, null, InputOption::VALUE_NEGATABLE, 'Add as stops? (alias for `sl:grid` command)')
        ;

        CreateStopsGridCommand::configureStopsArguments($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $this->getSymbol();
        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());
        $ticker = $this->exchangeService->ticker($symbol);
        $position = $this->getPosition();

        $wishedLiquidationDistance = $this->paramFetcher->getFloatArgument(self::WISHED_LIQUIDATION_DISTANCE);

        $wishedLiquidationPrice = $ticker->markPrice->modifyByDirection($position->side, PriceMovementDirection::TO_LOSS, $wishedLiquidationDistance);

        $this->io->info(sprintf('Wished liquidation price: %s', $wishedLiquidationPrice));

        $result = $this->volumeCalculator->handle(
            new CalcPositionVolumeBasedOnLiquidationPriceEntryDto(
                $position, $contractBalance, $contractBalance->freeForLiquidation, $wishedLiquidationPrice, $ticker->lastPrice
            )
        );
        $calculatedDiff = $result->diff;

        $msg = sprintf('Need to close %s (%s). Continue? Estimated liquidationPrice = %s', $calculatedDiff, Percent::fromPart($calculatedDiff / $position->size), $result->realLiquidationPrice);
        if (!$this->isWithoutConfirm() && !$this->io->confirm($msg)) {
            return Command::SUCCESS;
        } else {
            $this->io->info($msg);
        }

        if ($this->asStop()) {
            $params = [];
            foreach ($input->getArguments() as $name => $value) {
                if ($name === 'command') continue;
                $params[$name] = $value;
            }
            foreach ($input->getOptions() as $name => $value) {
                if (!in_array($name, array_merge(['symbol', CreateStopsGridCommand::ORDERS_QNT_OPTION, $this->fromOptionName, $this->toOptionName], $this->addedAdditionalStopContexts))) {
                    continue;
                }
                $params[sprintf('--%s', $name)] = (string)$value;
            }
            unset($params['wishedLiquidationDistance'], $params['--y'], $params['--as-stop'], $params['--no-interaction']);

            $slGridInput = new ArrayInput(array_merge([
                'command' => 'sl:grid',
                'forVolume' => (string)$calculatedDiff,
            ], $params));

            $this->getApplication()->doRun($slGridInput, $output);
        } else {
            $this->orderService->closeByMarket($position, $calculatedDiff);

            $newPositionState = $this->getPosition();

            self::printLiquidationStats('wished        ', $wishedLiquidationPrice);
            self::printLiquidationStats('preCalculated ', $result->realLiquidationPrice);
            self::printLiquidationStats('real          ', $newPositionState->liquidationPrice());
            OutputHelper::print(sprintf('                      real - preCalculated : %.3f', $result->realLiquidationPrice->differenceWith($newPositionState->liquidationPrice())->deltaForPositionLoss($newPositionState->side)));
            OutputHelper::print(sprintf('                      real - wished : %.3f', $wishedLiquidationPrice->differenceWith($newPositionState->liquidationPrice())->deltaForPositionLoss($newPositionState->side)));

            $this->io->info(sprintf('New position stats: size = %s, liquidation = %s', $newPositionState->size, $newPositionState->liquidationPrice()));
        }


        return Command::SUCCESS;
    }

    public static function printLiquidationStats(string $desc, Price $liq): void
    {
        OutputHelper::print(sprintf('%s | liq = %s', $desc, $liq));
    }

    private function isWithoutConfirm(): bool
    {
        return $this->paramFetcher->getBoolOption(self::WITHOUT_CONFIRMATION_OPTION);
    }

    private function asStop(): bool
    {
        return $this->paramFetcher->getBoolOption(self::AS_STOP_OPTION);
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly CalcPositionVolumeBasedOnLiquidationPriceHandler $volumeCalculator,
        private readonly OrderServiceInterface $orderService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
