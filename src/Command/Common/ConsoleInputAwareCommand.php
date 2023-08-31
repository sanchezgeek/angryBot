<?php

declare(strict_types=1);

namespace App\Command\Common;

use App\Console\ConsoleParamFetcher;

trait ConsoleInputAwareCommand
{
    private ConsoleParamFetcher $paramFetcher;

    private function setParamFetcher(ConsoleParamFetcher $paramFetcher): static
    {
        $this->paramFetcher = $paramFetcher;

        return $this;
    }
}
