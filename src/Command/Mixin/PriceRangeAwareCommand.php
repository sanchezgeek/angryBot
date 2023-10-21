<?php

declare(strict_types=1);

namespace App\Command\Mixin;

use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

use function sprintf;

trait PriceRangeAwareCommand
{
    private string $fromOptionName = 'from';
    private string $toOptionName = 'to';

    private function configurePriceRangeArgs(
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

    private function getPriceRange(): PriceRange
    {
        $fromPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback($this->fromOptionName);
        $toPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback($this->toOptionName);
        if ($fromPrice->greater($toPrice)) {
            [$fromPrice, $toPrice] = [$toPrice, $fromPrice];
        }

        return new PriceRange($fromPrice, $toPrice);
    }
}
