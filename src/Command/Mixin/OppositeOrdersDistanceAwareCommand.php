<?php

declare(strict_types=1);

namespace App\Command\Mixin;

use App\Domain\Value\Percent\Percent;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

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
    protected function getOppositeOrdersDistanceOption(): Percent|float|null
    {
        $name = $this->oppositeDistanceOption;

        try {
            return $this->paramFetcher->requiredPercentOption($name);
        } catch (InvalidArgumentException) {
            return $this->paramFetcher->floatOption($name);
        }
    }
}
