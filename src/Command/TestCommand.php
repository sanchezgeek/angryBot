<?php

namespace App\Command;

use App\Application\Notification\AppNotificationLoggerInterface;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cmd:test')]
class TestCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->notificationLogger->notify('sdf', ['sdf' => 'sdf']);
//        $this->exchangeService->getTickers(Symbol::BTCUSDT, Symbol::ETHUSDT);
    }

    public function __construct(
        private readonly ByBitLinearExchangeService $exchangeService,
        private readonly AppNotificationLoggerInterface $notificationLogger,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
