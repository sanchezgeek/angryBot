<?php

namespace App\Command\Position;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Worker\AppContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function sprintf;

#[AsCommand(name: 'p:info')]
class PositionsInfoCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    public const DEBUG_OPTION = 'deb';

    protected function configure(): void
    {
        $this->configureSymbolArgs()
            ->addOption(self::DEBUG_OPTION, null, InputOption::VALUE_NEGATABLE, 'Debug?');
    }

    private function isDebugEnabled(): bool
    {
        return $this->paramFetcher->getBoolOption(self::DEBUG_OPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->isDebugEnabled()) {
            AppContext::setIsDebug(true);
        }

        $positions = $this->positionService->getPositions($this->getSymbol());
        $position = count($positions) > 1 ? $positions[0]->getHedge()->mainPosition : $positions[0];

        $freeContractBalance = $this->exchangeAccountService->calcFreeContractBalance($position);

        if ($this->isDebugEnabled() && ($hedge = $position->getHedge())) {
            $ticker = $this->exchangeService->ticker($position->symbol);
            OutputHelper::printIfDebug($hedge->info($ticker));
        }

        $this->printState($position, $this->calcPositionLiquidationPriceHandler->handle($position, $freeContractBalance));
        echo '-------------------- docs --------------- ';  $this->printState($position, $this->calcPositionLiquidationPriceHandler->handleFromDocs($position, $freeContractBalance));

        return Command::SUCCESS;
    }

    public function printState(?Position $position, CalcPositionLiquidationPriceResult $result): void
    {
        OutputHelper::print('');
        OutputHelper::positionStats('real      ', $position);
        OutputHelper::print(
            sprintf('calculated | entry = %.2f | Liquidation = %.2f | LiquidationDistance = %.2f', $result->positionEntryPrice()->value(), $result->estimatedLiquidationPrice()->value(), $result->liquidationDistance()),
            sprintf('                                                            real - calculated : %.3f', (
                $position->isShort() ? $result->liquidationDistance() - $position->liquidationDistance() : $position->liquidationDistance() - $result->liquidationDistance()
            )),
        );
    }

    /**
     * @param ByBitExchangeAccountService $exchangeAccountService
     */
    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly CalcPositionLiquidationPriceHandler $calcPositionLiquidationPriceHandler,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
