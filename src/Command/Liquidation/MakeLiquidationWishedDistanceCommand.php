<?php

namespace App\Command\Liquidation;

use App\Application\UniqueIdGeneratorInterface;
use App\Application\UseCase\Position\CalcPositionVolumeBasedOnLiquidationPrice\CalcPositionVolumeBasedOnLiquidationPriceEntryDto;
use App\Application\UseCase\Position\CalcPositionVolumeBasedOnLiquidationPrice\CalcPositionVolumeBasedOnLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\ForwardedCommandExecutor;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OppositeOrdersDistanceAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\PositionDependentCommand;
use App\Command\Stop\CreateStopsGridCommand;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\SymbolPrice;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'liq:wish-distance')]
class MakeLiquidationWishedDistanceCommand extends AbstractCommand implements PositionDependentCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use PriceRangeAwareCommand;
    use AdditionalStopContextAwareCommand;
    use OppositeOrdersDistanceAwareCommand;

    public const WISHED_LIQUIDATION_DISTANCE_OPTION = 'distance';
    public const WISHED_LIQUIDATION_PRICE_OPTION = 'price';

    public const WITHOUT_CONFIRMATION_OPTION = 'y';
    public const AS_STOP_OPTION = 'as-stop';

    private array $addedArguments = [];

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption(self::WISHED_LIQUIDATION_DISTANCE_OPTION, null, InputOption::VALUE_REQUIRED, 'Wished liquidation price distance with ticker')
            ->addOption(self::WISHED_LIQUIDATION_PRICE_OPTION, null, InputOption::VALUE_REQUIRED, 'Wished liquidation price')
            ->addOption(self::WITHOUT_CONFIRMATION_OPTION, null, InputOption::VALUE_NEGATABLE, 'Without confirm')
            ->addOption(self::AS_STOP_OPTION, null, InputOption::VALUE_NEGATABLE, 'Add as stops? (alias for `sl:grid` command)')
        ;

        CreateStopsGridCommand::configureStopsGridArguments($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $this->getSymbol();
        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());
        $fundsAvailableForLiquidation = $this->exchangeAccountService->calcFundsAvailableForLiquidation($symbol, $contractBalance);
        $position = $this->getPosition();
        $ticker = $this->exchangeService->ticker($symbol);

        if (!$wishedLiquidationPrice = $this->paramFetcher->floatOption(self::WISHED_LIQUIDATION_PRICE_OPTION)) {
            $wishedLiquidationDistance = $this->paramFetcher->requiredFloatOption(self::WISHED_LIQUIDATION_DISTANCE_OPTION);
            $wishedLiquidationPrice = $ticker->markPrice->modifyByDirection($position->side, PriceMovementDirection::TO_LOSS, $wishedLiquidationDistance);
        } else {
            $wishedLiquidationPrice = $symbol->makePrice($wishedLiquidationPrice);
        }

        $this->io->info(sprintf('Wished liquidation price: %s', $wishedLiquidationPrice));

        $result = $this->volumeCalculator->handle(
            new CalcPositionVolumeBasedOnLiquidationPriceEntryDto(
                $position, $contractBalance, $fundsAvailableForLiquidation, $wishedLiquidationPrice, $ticker->lastPrice
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
            $commandExecutor = new ForwardedCommandExecutor($this->getApplication());
            $commandExecutor->execute(CreateStopsGridCommand::NAME, $input, $output, [CreateStopsGridCommand::FOR_VOLUME_ARGUMENT => (string)$calculatedDiff]);
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

    public static function printLiquidationStats(string $desc, SymbolPrice $liq): void
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
        private readonly ByBitExchangeAccountService $exchangeAccountService,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
