<?php

declare(strict_types=1);

namespace App\Command\Mixin;

use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

use function count;
use function explode;
use function sprintf;

trait PriceRangeAwareCommand
{
    use ConsoleInputAwareCommand;

    private string $fromOptionName = 'from';
    private string $toOptionName = 'to';

    protected function configurePriceRangeArgs(
        string $fromName = 'from', string $fromAlias = 'f',
        string $toName = 'to', string $toAlias = 't',
    ): static {
        $this->fromOptionName = $fromName;
        $this->toOptionName = $toName;

        $this
            ->addOption($fromName, sprintf('-%s', $fromAlias), InputOption::VALUE_REQUIRED, '`from` price | PNL%')
            ->addOption($toName, sprintf('-%s', $toAlias), InputOption::VALUE_REQUIRED, '`to` price | PNL%')
        ;

        return $this;
    }

    protected function getPriceFromPnlPercentOptionWithFloatFallback(string $name, bool $required = true): ?Price
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

    protected function getPriceRange(): PriceRange
    {
        $fromPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback($this->fromOptionName);
        $toPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback($this->toOptionName);

        return PriceRange::create($fromPrice, $toPrice);
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
