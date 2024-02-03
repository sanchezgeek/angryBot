<?php

declare(strict_types=1);

namespace App\Command\Mixin\OrderContext;

use App\Bot\Domain\Entity\BuyOrder;
use App\Command\Mixin\ConsoleInputAwareCommand;
use Symfony\Component\Console\Input\InputOption;

trait AdditionalBuyOrderContextAwareCommand
{
    use ConsoleInputAwareCommand;

    private const NEGATABLE_OPTIONS = [
        'withShortStop' => [
            'caption' => 'With short stop',
            'mappedContext' => BuyOrder::WITH_SHORT_STOP_CONTEXT,
        ],
        'wOO' => [
            'caption' => 'Without opposite order',
            'mappedContext' => BuyOrder::WITHOUT_OPPOSITE_ORDER_CONTEXT,
        ],
    ];

    protected function configureBuyOrderAdditionalContexts(): static
    {
        foreach (self::NEGATABLE_OPTIONS as $option => ['caption' => $caption]) {
            $this->addOption($option, null, InputOption::VALUE_NEGATABLE, $caption);
        }

        return $this;
    }

    protected function getAdditionalBuyOrderContext(): ?array
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
