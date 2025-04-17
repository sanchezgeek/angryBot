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
        'wOO' => [
            'caption' => 'Without opposite order',
            'mappedContext' => Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT,
        ],
        'fH' => [
            'caption' => 'Fix opposite position on loss',
            'mappedContext' => Stop::FIX_HEDGE_ON_LOSS,
        ],
        'bM' => [
            'caption' => 'Close By Market',
            'mappedContext' => Stop::CLOSE_BY_MARKET_CONTEXT,
        ],
    ];

    private array $addedAdditionalStopContexts = [];

    public function configureStopAdditionalContexts(): static
    {
        foreach (self::NEGATABLE_OPTIONS as $option => ['caption' => $caption]) {
            $this->addOption($option, null, InputOption::VALUE_NEGATABLE, $caption);
            $this->addedAdditionalStopContexts[] = $option;
        }

        return $this;
    }

    protected function getAdditionalStopContext(): ?array
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
