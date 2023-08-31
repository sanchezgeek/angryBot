<?php

declare(strict_types=1);

namespace App\Command\Mixin\OrderContext;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Command\Mixin\ConsoleInputAwareCommand;
use Symfony\Component\Console\Input\InputOption;

trait AdditionalStopContextAwareCommand
{
    use ConsoleInputAwareCommand;

    private const NEGATABLE_OPTIONS = [
        'withoutOppositeOrder' => [
            'caption' => 'Without opposite order',
            'mappedContext' => Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT_NAME,
        ],
    ];

    private function configureStopAdditionalContexts(): static
    {
        foreach (self::NEGATABLE_OPTIONS as $option => ['caption' => $caption]) {
            $this->addOption($option, null, InputOption::VALUE_NEGATABLE, $caption);
        }

        return $this;
    }

    private function getAdditionalStopContext(): ?array
    {
        $additionalContext = [];

        foreach (self::NEGATABLE_OPTIONS as $option => ['mappedContext' => $contextName]) {
            if ($this->paramFetcher->getBoolOption($option)) {
                $additionalContext[$contextName] = true;
            }
        }

        return $additionalContext ?: null;
    }
}
