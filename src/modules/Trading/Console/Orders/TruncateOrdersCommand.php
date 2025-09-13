<?php

namespace App\Trading\Console\Orders;

use App\Trading\Application\UseCase\TruncateOrders\TruncateOrdersEntry;
use App\Trading\Application\UseCase\TruncateOrders\TruncateOrdersHandler;
use App\UserInteraction\HandlerMessage\AbstractExecuteHandlerMessageCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'o:truncate')]
class TruncateOrdersCommand extends AbstractExecuteHandlerMessageCommand
{
    private const string AFTER_SL = 'after-sl';
    private const string DRY_RUN = 'dry';

    protected function configure(): void
    {
        $this
            ->addOption(self::AFTER_SL, null, InputOption::VALUE_NEGATABLE)
            ->addOption(self::DRY_RUN, null, InputOption::VALUE_NEGATABLE)
        ;
    }

    protected function getHandledMessageClass(): string
    {
        return TruncateOrdersEntry::class;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var TruncateOrdersEntry $entry */
        $entry = $this->initializeHandledMessage();

        if ($this->paramFetcher->getBoolOption(self::AFTER_SL)) {
            $entry->addPredefinedBuyOrderFilterCallback(TruncateOrdersEntry::AFTER_SL_CALLBACK_ALIAS);
        }

        if ($this->paramFetcher->getBoolOption(self::DRY_RUN)) {
            $entry->setDryRun();
        }

        $result = ($this->handler)($entry);

        $this->io->info($result);

        return self::SUCCESS;
    }

    public function __construct(
        private readonly TruncateOrdersHandler $handler,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
