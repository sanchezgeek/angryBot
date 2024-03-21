<?php

namespace App\Command\Trade;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Order\Order;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use InvalidArgumentException;
use RuntimeException;
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

    private const MARKET_BUY = 'buy';
    private const MARKET_CLOSE = 'close';
    private const LIMIT_TP = 'limitTP';
    private const DELAYED_TP = 'delayedTP';

    private const TYPES = [self::MARKET_BUY, self::MARKET_CLOSE, self::LIMIT_TP, self::DELAYED_TP];

    private const TP_PRICE_OPTION = 'tpPrice';
    private const LIMIT_TP_PRICE_STEP_OPTION = 'step';

    public const VOLUME_ARGUMENT = 'volume';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument(self::VOLUME_ARGUMENT, InputArgument::REQUIRED, sprintf('Volume value || %% of position size (in `%s` mode)', self::MARKET_CLOSE))
            ->addOption(self::TYPE_OPTION, '-k', InputOption::VALUE_REQUIRED, 'Type (' . implode(', ', self::TYPES) . ')')
            ->addOption(self::TP_PRICE_OPTION, '-p', InputOption::VALUE_REQUIRED, 'Limit TP price | PositionPNL%. Use [`from` + `to` + `step`], if you need place orders in price range (actual for `limitTP` and `delayedTP` modes)')
            ->addOption(self::LIMIT_TP_PRICE_STEP_OPTION, '-s', InputOption::VALUE_REQUIRED, 'Price step (in case of `from` + `to`)')
            ->configurePriceRangeArgs()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $this->getType();

        if ($type === self::MARKET_BUY) {
            $symbol = $this->getSymbol();
            $side = $this->getPositionSide();
            $volume = $this->paramFetcher->getFloatArgument(self::VOLUME_ARGUMENT);

            $msg = sprintf('You\'re about to buy %s on "%s %s". Continue?', $volume, $symbol->value, $side->title());

            if (!$this->io->confirm($msg)) {
                return Command::FAILURE;
            }

            $this->tradeService->marketBuy($symbol, $side, $volume);
        }

        if ($type === self::MARKET_CLOSE) {
            // @todo | maybe need move to some trait (code duplicates)
            $positionSizePart = null;
            try {
                $positionSizePart = $this->paramFetcher->getPercentArgument(self::VOLUME_ARGUMENT);
                $volume = $this->getPosition()->getVolumePart($positionSizePart);
            } catch (InvalidArgumentException|RuntimeException) {
                $volume = $this->paramFetcher->getFloatArgument(self::VOLUME_ARGUMENT);
            }
            $position = $this->getPosition();

            $msg = sprintf(
                'You\'re about to close %s of "%s" position. Continue?',
                $positionSizePart !== null ? sprintf('%.1f%%', $positionSizePart) : $volume,
                $position->getCaption()
            );

            if (!$this->io->confirm($msg)) {
                return Command::FAILURE;
            }

            $this->tradeService->closeByMarket($position, $volume);
        }

        if (in_array($type, [self::LIMIT_TP, self::DELAYED_TP])) {
            $volume = $this->paramFetcher->getFloatArgument(self::VOLUME_ARGUMENT);

            $position = $this->getPosition();

            try {
                $priceRange = $this->getPriceRange();
            } catch (\InvalidArgumentException $e) {
                $price = $this->getPriceFromPnlPercentOptionWithFloatFallback(self::TP_PRICE_OPTION);
            }

            $orders = [];
            if (isset($priceRange)) {
                $step = $this->paramFetcher->getIntOption(self::LIMIT_TP_PRICE_STEP_OPTION);

                foreach ($priceRange->byStepIterator($step) as $price) {
                    $rand = round(random_int(-7, 8) * 0.4, 2);
                    $orders[] = new Order($price->sub($rand), $volume);
                }

                $msg = sprintf('You\'re about to add %d %.3f %ss on "%s" position. Continue?', count($orders), $volume, $type, $position->getCaption());
                if (!$this->io->confirm($msg)) {
                    return Command::FAILURE;
                }
            } elseif (isset($price)) {
                $msg = sprintf('You\'re about to add %s on "%s" position. Continue?', $type, $position->getCaption());
                if (!$this->io->confirm($msg)) {
                    return Command::FAILURE;
                }
                $orders[] = new Order($price, $volume);
            } else {
                throw new InvalidArgumentException('`priceRange` or `price` must be specified');
            }

            $context = ['uniqid' => $uniqueId = $this->uniqueIdGenerator->generateUniqueId('delayed-tp-grid')];
            foreach ($orders as $order) {
                if ($type === self::DELAYED_TP) {
                    $context = array_merge($context, Stop::getTakeProfitContext());
                    $this->stopService->create($position->side, $order->price()->value(), $order->volume(), Stop::getTakeProfitTriggerDelta(), $context);
                } else {
                    $this->tradeService->addLimitTP($position, $order->volume(), $order->price()->value());
                }
            }

            if ($type === self::DELAYED_TP) {
                $this->io->success(sprintf('DelayedTPs uniqueID: %s', $uniqueId));
            }
        }

        return Command::SUCCESS;
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
            $pnlValue = $this->paramFetcher->requiredPercentOption($name);
            return PnlHelper::targetPriceByPnlPercentFromPositionEntry($this->getPosition(), $pnlValue);
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
        private readonly StopServiceInterface $stopService,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
