<?php

declare(strict_types=1);

namespace App\Command\Account;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Command\AbstractCommand;
use App\Command\Mixin\CommandRunnerCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Value\Percent\Percent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'balance:cover-losses')]
class CoverLossesCommand extends AbstractCommand
{
    use SymbolAwareCommand;
    use PriceRangeAwareCommand;
    use CommandRunnerCommand;

    protected function configure(): void
    {
        $this->configureSymbolArgs();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $this->getSymbol();

        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());
        if ($contractBalance->available() > 0) {
            $this->io->info('Available contract balance is greater than 0. Exit.');
            return Command::FAILURE;
        }

        $positions = $this->positionService->getPositions($symbol);

        $summaryIM = 0;
        foreach ($positions as $position) {
            $summaryIM += $position->initialMargin->value();
        }

        $diff = $summaryIM - $contractBalance->total();
        if ($diff < 0) {
            $this->io->info('Nothing to cover. Exit.');
            return Command::FAILURE;
        }

        $position = null;
        if (count($positions) > 1) {
            if (($fromPosition = $this->io->ask('Which position profit use to cover losses?', 'main')) === 'support') {
                $position = $positions[0]->getHedge()->supportPosition;
            } elseif ($fromPosition === 'main') {
                $position = $positions[0]->getHedge()->mainPosition;
            } else {
                throw new \InvalidArgumentException('Provided value must be "main" or "support"');
            }
        } else {
            $position = $positions[0];
        }

        if (!$position->isPositionInProfit($this->exchangeService->ticker($symbol)->lastPrice)) {
            throw new \InvalidArgumentException('Position in loss');
        }

        $percentToClose = new Percent($diff / $position->unrealizedPnl);

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly CalcPositionLiquidationPriceHandler $calcPositionLiquidationPriceHandler,
        private readonly PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly HedgeService $hedgeService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
