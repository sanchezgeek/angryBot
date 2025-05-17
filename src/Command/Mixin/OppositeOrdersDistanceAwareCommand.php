<?php

declare(strict_types=1);

namespace App\Command\Mixin;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function sprintf;

trait OppositeOrdersDistanceAwareCommand
{
    use ConsoleInputAwareCommand;
    use PriceRangeAwareCommand;

    private string $oppositeDistanceOption = 'oppositeDistance';

    public function configureOppositeOrdersDistanceOption(
        string $name = 'oppositeDistance',
        ?string $alias = null,
    ): static
    {
        $this->oppositeDistanceOption = $name;

        return $this
            ->addOption($name, $alias, InputOption::VALUE_REQUIRED, 'Opposite orders distance (abs. or %)')
        ;
    }

    /**
     * @throws Throwable
     */
    protected function getOppositeOrdersDistanceOption(Symbol $symbol): ?float
    {
        $name = $this->oppositeDistanceOption;

        try {
            $pnlValue = $this->paramFetcher->requiredPercentOption($name);

            try {
                $basedOnPrice = $this->exchangeService->ticker($symbol)->indexPrice;
            } catch (\Throwable $e) {
                if (!$this->io->confirm(sprintf('Got "%s" error while do `ticker` request. Want to use price from specified price range?', $e->getMessage()))) {
                    throw $e;
                }
                $basedOnPrice = $this->getPriceRange()->getMiddlePrice();
            }

            return PnlHelper::convertPnlPercentOnPriceToAbsDelta($pnlValue, $basedOnPrice);
        } catch (InvalidArgumentException) {
            return $this->paramFetcher->floatOption($name);
        }
    }
}
