<?php

namespace App\Infrastructure\Symfony\Monolog;

use App\Worker\AppContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class AddAdditionalDataToLogsProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['acc_name'] = AppContext::accName() ?? 'null';
        $record->extra['worker_name'] = AppContext::runningWorker() ? AppContext::runningWorker()->name : 'null';

        return $record;
    }
}
