<?php

namespace App\Command\Trade;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Order\Order;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function in_array;
use function sprintf;

#[AsCommand(name: 'order:place')]
class PlaceOrderCommand extends AbstractCommand
{
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    private const TYPE_OPTION = 'type';
    private const MARKET_CLOSE = 'market';
    private const LIMIT_TP = 'limitTP';

    private const TYPES = [self::MARKET_CLOSE, self::LIMIT_TP];

    private const LIMIT_TP_PRICE_OPTION = 'limitTpPrice';
    private const LIMIT_TP_PRICE_STEP_OPTION = 'step';

    public const FOR_VOLUME_OPTION = 'forVolume';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->configurePriceRangeArgs()
            ->addArgument(self::FOR_VOLUME_OPTION, InputArgument::REQUIRED, 'Volume value || $ of position size')
            ->addOption(self::TYPE_OPTION, '-k', InputOption::VALUE_REQUIRED, 'Type (' . implode(', ', self::TYPES) . ')', self::LIMIT_TP)
            ->addOption(self::LIMIT_TP_PRICE_OPTION, '-p', InputOption::VALUE_REQUIRED, 'Limit TP price | PositionPNL%')
            ->addOption(self::LIMIT_TP_PRICE_STEP_OPTION, '-s', InputOption::VALUE_REQUIRED, 'Price step (in case of `priceRange`)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [$forVolume, $positionSizePart] = $this->getForVolumeParam();
        $position = $this->getPosition();

        $type = $this->getType();

        if ($type === self::MARKET_CLOSE) {
            $msg = sprintf(
                'You\'re about to close %s of %s. Continue?',
                $positionSizePart !== null ? sprintf('%.1f%%', $positionSizePart) : $forVolume,
                $position->getCaption()
            );

            if (!$this->io->confirm($msg)) {
                return Command::FAILURE;
            }

            $this->tradeService->closeByMarket($position, $forVolume);
        }

        if ($type === self::LIMIT_TP) {
            try {
                $priceRange = $this->getPriceRange();
            } catch (\InvalidArgumentException $e) {
                $price = $this->getPriceFromPnlPercentOptionWithFloatFallback(self::LIMIT_TP_PRICE_OPTION);
            }

            if (isset($priceRange)) {
                $step = $this->paramFetcher->getIntOption(self::LIMIT_TP_PRICE_STEP_OPTION);

                $orders = [];
                foreach ($priceRange->byStepIterator($step) as $price) {
                    $orders[] = new Order($price, $forVolume);
                }

                $msg = sprintf('You\'re about to add %d %.3f limitTPs on %s. Continue?', count($orders), $forVolume, $position->getCaption());
                if (!$this->io->confirm($msg)) {
                    return Command::FAILURE;
                }

                foreach ($orders as $order) {
                    $this->tradeService->addLimitTP($position, $order->volume(), $order->price()->value());
                }
            } elseif (isset($price)) {
                $msg = sprintf('You\'re about to add limitTP on %s. Continue?', $position->getCaption());
                if (!$this->io->confirm($msg)) {
                    return Command::FAILURE;
                }
                $this->tradeService->addLimitTP($position, $forVolume, $price->value());
            } else {
                throw new InvalidArgumentException('`priceRange` or `price` must be specified');
            }
        }

        return Command::SUCCESS;
    }

    private function getForVolumeParam(): array
    {
        $positionSizePart = null;
        try {
            $positionSizePart = $this->paramFetcher->getPercentArgument(self::FOR_VOLUME_OPTION);
            $forVolume = $this->getPosition()->getVolumePart($positionSizePart);
        } catch (InvalidArgumentException) {
            $forVolume = $this->paramFetcher->getFloatArgument(self::FOR_VOLUME_OPTION);
        }

        return [$forVolume, $positionSizePart];
    }

    private function getType(): string
    {
        $mode = $this->paramFetcher->getStringOption(self::TYPE_OPTION);
        if (!in_array($mode, self::TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $mode provided (%s)', $mode),
            );
        }

        return $mode;
    }

    private function getPriceFromPnlPercentOptionWithFloatFallback(string $name, bool $required = true): ?Price
    {
        try {
            $pnlValue = $this->paramFetcher->getPercentOption($name);
            return PnlHelper::getTargetPriceByPnlPercent($this->getPosition(), $pnlValue);
        } catch (InvalidArgumentException) {
            try {
                return Price::float($this->paramFetcher->requiredFloatOption($name));
            } catch (InvalidArgumentException $e) {
                if ($required) {
                    throw $e;
                }

                return null;
            }
        }
    }

    public function __construct(
        private readonly OrderServiceInterface $tradeService,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
