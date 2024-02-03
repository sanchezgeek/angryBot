<?php

declare(strict_types=1);

namespace App\Command\Mixin\OrderContext;

use App\Command\Mixin\ConsoleInputAwareCommand;
use Symfony\Component\Console\Input\InputOption;

trait RawAdditionalOrderContextAwareCommand
{
    use ConsoleInputAwareCommand;

    private const ADDITIONAL_CONTEXT_ARGUMENT_NAME = 'rawc';

    protected function configureAdditionalContextOption(): static
    {
        $this->addOption(self::ADDITIONAL_CONTEXT_ARGUMENT_NAME, null, InputOption::VALUE_OPTIONAL, 'Raw additional context');

        return $this;
    }

    protected function getRawAdditionalContext(): ?array
    {
        return $this->paramFetcher->getJsonArrayOption(self::ADDITIONAL_CONTEXT_ARGUMENT_NAME);
    }
}
