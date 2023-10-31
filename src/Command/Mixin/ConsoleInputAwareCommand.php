<?php

declare(strict_types=1);

namespace App\Command\Mixin;

use App\Console\ConsoleParamFetcher;
use Symfony\Component\Console\Input\InputInterface;

trait ConsoleInputAwareCommand
{
    protected ConsoleParamFetcher $paramFetcher;

    private function withInput(InputInterface $input): static
    {
        $this->paramFetcher = new ConsoleParamFetcher($input);

        return $this;
    }
}
