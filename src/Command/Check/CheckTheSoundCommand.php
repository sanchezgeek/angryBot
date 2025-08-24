<?php

namespace App\Command\Check;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Command\AbstractCommand;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'c:sound')]
class CheckTheSoundCommand extends AbstractCommand
{
    private const string CHANNEL_OPTION = 'channel';
    private const string ERROR_CHANNEL = 'error';
    private const string NOTIFICATION_CHANNEL = 'notification';

    private const string NOTIFICATION_TYPE_OPTION = 'type';
    private const string NOTIFICATION_TYPE = 'info';

    protected function configure(): void
    {
        $this
            ->addOption(self::CHANNEL_OPTION, 'c', InputOption::VALUE_REQUIRED, '', self::ERROR_CHANNEL)
            ->addOption(self::NOTIFICATION_TYPE_OPTION, 't', InputOption::VALUE_REQUIRED, '', self::NOTIFICATION_TYPE)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $this->paramFetcher->getStringOption(self::CHANNEL_OPTION);

        $notificationType = $this->paramFetcher->getStringOption(self::NOTIFICATION_TYPE_OPTION);

        match (true) {
            $channel === self::ERROR_CHANNEL => $this->appErrorLogger->error('check-the-sound'),
            $channel === self::NOTIFICATION_CHANNEL => $this->notificationsService->notify(message: 'check-the-sound', type: $notificationType),
            default => 'Unrecognized options'
        };

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly AppErrorLoggerInterface $appErrorLogger,
        private readonly AppNotificationsServiceInterface $notificationsService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
