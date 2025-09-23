<?php

namespace App\Screener\Console;

use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Coin\Coin;
use App\Domain\Position\ValueObject\Side;
use App\Helper\OutputHelper;
use App\Screener\Application\Contract\Query\FindSignificantPriceChange;
use App\Screener\Application\Contract\Query\FindSignificantPriceChangeHandlerInterface;
use App\Screener\Application\Contract\Query\FindSignificantPriceChangeResponse;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'screener:significant-price-change:find')]
class FindSignificantPriceChangeCommand extends AbstractCommand
{
    use PositionAwareCommand;

    public const string DAYS_DELTA_OPTION = 'days-delta';
    public const string BASE_ATR_MULTIPLIER_OPTION = 'atr-multiplier';
    public const string BIAS = 'bias';

    private int $daysDelta;
    private ?float $atrMultiplier;
//    private RiskLevel $riskLevel;

    protected function configure(): void
    {
        $this
            ->addOption(self::DAYS_DELTA_OPTION, 'd', InputOption::VALUE_REQUIRED, '', '0')
            ->addOption(self::BASE_ATR_MULTIPLIER_OPTION, 'm', InputOption::VALUE_OPTIONAL)
            ->addOption(self::BIAS, 'b', InputOption::VALUE_REQUIRED)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->daysDelta = $this->paramFetcher->getIntOption(self::DAYS_DELTA_OPTION);
        $this->atrMultiplier = $this->paramFetcher->floatOption(self::BASE_ATR_MULTIPLIER_OPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entry = new FindSignificantPriceChange(
            Coin::USDT,
            $this->daysDelta,
            $this->atrMultiplier,
            true,
        );

        $bias = $this->paramFetcher->getStringOption(self::BIAS);
        $bias = Side::from($bias);

        $result = $this->handler->handle($entry);

        $r = [];
        foreach ($result as $item) {
            if ($this->positionSideToPositionLoss($item) !== $bias) {
                continue;
            }

            $r[] = $item;
        }

        OutputHelper::print($r);die;

        return Command::SUCCESS;
    }

    public function positionSideToPositionLoss(FindSignificantPriceChangeResponse $info): Side
    {
        $priceMovement = $info->info->getPriceMovement();

        return $priceMovement->isLossFor(Side::Sell) ? Side::Sell : Side::Buy;
    }

    public function __construct(
        private readonly FindSignificantPriceChangeHandlerInterface $handler,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
