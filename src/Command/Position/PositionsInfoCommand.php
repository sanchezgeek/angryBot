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
use App\Infrastructure\Cache\PositionsCache;
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
        $this->positionsCache->clearPositionsCache($this->getSymbol());

        if ($this->isDebugEnabled()) {
            AppContext::setIsDebug(true);
        }

        $symbol = $this->getSymbol();
        $positions = $this->positionService->getPositions($symbol);

        if (!$positions) {
            $this->io->error('No positions found'); return Command::FAILURE;
        }

        $hedge = $positions[0]->getHedge();
        if ($hedge?->isEquivalentHedge()) {
            $this->io->info('Equivalent hedge found'); return Command::SUCCESS;
        }

        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());
        $position = $hedge?->mainPosition ?? $positions[0];
//        if ($this->isDebugEnabled() && ($hedge = $position->getHedge())) {OutputHelper::printIfDebug($hedge->info());}

        $this->printState($position, $this->calcPositionLiquidationPriceHandler->handle($position, $contractBalance->free));
        echo '-------------------- docs --------------- ';  $this->printState($position, $this->calcPositionLiquidationPriceHandler->handleFromDocs($position, $contractBalance->free));

        return Command::SUCCESS;
    }

    public function printState(?Position $position, CalcPositionLiquidationPriceResult $result): void
    {
        OutputHelper::print($position->getCaption());
        OutputHelper::positionStats('real      ', $position);
        OutputHelper::print(
            sprintf('calculated | LiquidationDistance = %.2f', $result->liquidationDistance()),
            sprintf('                                                            real - calculated : %.3f', $position->liquidationDistance() - $result->liquidationDistance()),
        );
    }

    /**
     * @param ByBitExchangeAccountService $exchangeAccountService
     */
    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly CalcPositionLiquidationPriceHandler $calcPositionLiquidationPriceHandler,
        private readonly PositionsCache $positionsCache,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
