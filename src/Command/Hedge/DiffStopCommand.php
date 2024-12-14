<?php

declare(strict_types=1);

namespace App\Command\Hedge;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Domain\Position\Helper\PositionClone;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Command\AbstractCommand;
use App\Command\Mixin\CommandRunnerCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Coin\CoinAmount;
use App\Domain\Value\Percent\Percent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(name: 'hedge:diff-stop')]
class DiffStopCommand extends AbstractCommand
{
    use SymbolAwareCommand;
    use PriceRangeAwareCommand;
    use CommandRunnerCommand;

    public const TARGET_PRICE_OPTION = 'targetPrice';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addOption(self::TARGET_PRICE_OPTION, 'p', InputOption::VALUE_OPTIONAL, 'TP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $this->getSymbol();
        $coin = $symbol->associatedCoin();
        $targetPrice = $this->paramFetcher->requiredFloatOption(self::TARGET_PRICE_OPTION);
        $positions = $this->positionService->getPositions($symbol);

        if (!($hedge = $positions[0]->getHedge())) {
            return Command::INVALID;
        }

        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($coin);
        $mainPosition = $hedge->mainPosition;

        $isLiquidationPriceAlreadyOverSpecifiedPrice = $mainPosition->isShort()
            ? $mainPosition->liquidationPrice > $targetPrice
            : $mainPosition->liquidationPrice < $targetPrice
        ;
        if ($isLiquidationPriceAlreadyOverSpecifiedPrice) {
            $this->io->success('Liquidation price is already over provided price');
            return Command::SUCCESS;
        }

        $liquidationPrice = $mainPosition->liquidationPrice;

        $position = $mainPosition;
        while (
            ($mainPosition->isShort() && $liquidationPrice < $targetPrice)
            || ($mainPosition->isLong() && $liquidationPrice > $targetPrice)
        ) {
            // @todo | symbol
            $position = PositionClone::full($position)->withSize($position->size - 0.001)->create();
            $liquidationCalcResult = $this->calcPositionLiquidationPriceHandler->handle($position, $contractBalance->freeForLiquidation);

            $liquidationPrice = $liquidationCalcResult->estimatedLiquidationPrice()->value();
        }

        $sizeDiff = $mainPosition->size - $position->size;

        if ($sizeDiff > 0) {
            $percent = new Percent($sizeDiff / $mainPosition->size * 100);
            if ($this->io->confirm(sprintf('Do you want to add stops for this size diff? [%s of position size]', $percent), false)) {
                $range = $this->getRangePretty($this->io->ask('Provide `sl:grid` range:', '-10%..10%'));
                $slGridOptions = $this->io->ask('Provide `sl:grid` options:', '');
                $cmd = sprintf('php bin/console sl:grid %s -f%s -t%s %s %s',
                    $mainPosition->side->value,
                    $range[0], $range[1],
                    $percent,
                    $slGridOptions,
                );

                if ($this->io->confirm(sprintf('`%s`. Sure?', $cmd), false)) {
                    self::cmd($cmd);
                }
            }
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly CalcPositionLiquidationPriceHandler $calcPositionLiquidationPriceHandler,
        private readonly PositionServiceInterface $positionService,
        private readonly HedgeService $hedgeService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
