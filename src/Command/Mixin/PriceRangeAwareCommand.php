<?php

declare(strict_types=1);

namespace App\Command\Mixin;

use App\Buy\Domain\Enum\PredefinedStopLengthSelector;
use App\Domain\Price\PriceRange;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;

use function count;
use function explode;
use function sprintf;

trait PriceRangeAwareCommand
{
    use ConsoleInputAwareCommand;

    private TradingParametersProviderInterface $tradingParametersProvider;

    private string $fromOptionName = 'from';
    private string $toOptionName = 'to';

    public function configurePriceRangeArgs(
        string $fromName = 'from', string $fromAlias = 'f',
        string $toName = 'to', string $toAlias = 't',
        string $desc = 'PNL% (relative to opened position or ticker if no position opened)'
    ): static {
        $this->fromOptionName = $fromName;
        $this->toOptionName = $toName;

        $this
            ->addOption($fromName, sprintf('-%s', $fromAlias), InputOption::VALUE_REQUIRED, sprintf('`from` price | %s', $desc))
            ->addOption($toName, sprintf('-%s', $toAlias), InputOption::VALUE_REQUIRED, sprintf('`to` price | %s', $desc))
        ;

        return $this;
    }

    public function withTradingParameters(TradingParametersProviderInterface $tradingParametersProvider): void
    {
        $this->tradingParametersProvider = $tradingParametersProvider;
    }

    protected function getPriceFromPnlPercentOptionWithFloatFallback(string $name, bool $required = true): ?SymbolPrice
    {
        $position = $this->getPosition();

        try {
            $pnlValue = $this->paramFetcher->requiredPercentOption($name);
            return PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, $pnlValue);
        } catch (InvalidArgumentException) {
            $symbol = $this->getSymbol();

            try {
                return $symbol->makePrice($this->paramFetcher->requiredFloatOption($name));
            } catch (InvalidArgumentException $e) {
                if (!$providedValue = $this->paramFetcher->getStringOption($name, false)) {
                    return null;
                }

                $sign = 1;
                if (str_starts_with($providedValue, '-')) {
                    $sign = -1;
                    $providedValue = substr($providedValue, 1);
                }

                if ($length = PredefinedStopLengthSelector::tryFrom($providedValue)) {
                    $priceChangePercent = $this->tradingParametersProvider->regularPredefinedStopLengthPercent($symbol, $length)->value();

                    $pnlValue = $priceChangePercent * 100 * $sign;

                    return PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, $pnlValue);
                } else {
                    if ($required) {
                        throw $e;
                    }

                    return null;
                }
            }
        }
    }

    protected function getPriceRange(bool $required = true): ?PriceRange
    {
        $fromPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback($this->fromOptionName);
        $toPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback($this->toOptionName);

        if ($fromPrice && $toPrice) {
            return PriceRange::create($fromPrice, $toPrice, $this->getSymbol());
        }

        if ($required) {
            throw new RuntimeException('Price range must be provided');
        }

        return null;
    }

    protected function getRangePretty(string $input): array
    {
        $input = explode('..', $input);
        if (count($input) !== 2) {
            throw new InvalidArgumentException('Invalid range provided');
        }

        $from = $this->getRangeValue($input[0], 'from');
        $to = $this->getRangeValue($input[1], 'to');

        return [$from, $to];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function getRangeValue(string $input, string $name): string|float
    {
        try {
            $percent = $this->paramFetcher->fetchPercentValue($input, $name, 'option');
            return $percent . '%';
        } catch (InvalidArgumentException) {
            try {
                return $this->paramFetcher->fetchFloatValue($input, $name, 'option');
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException(
                    sprintf('Percent or float value expected ("%s" provided).', $input)
                );
            }
        }
    }
}
