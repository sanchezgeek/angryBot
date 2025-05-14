<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Logger;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

trait AppErrorsSymfonyLoggerTrait
{
    static private ?LoggerInterface $testAppLogger = null;

    /**
     * @todo find __construct that must receive that
     */
    protected static function getTestAppErrorsLogger(): LoggerInterface
    {
        if (self::$testAppLogger !== null) {
            return self::$testAppLogger;
        }

        $logger = new Logger('name');
        /** @see monolog.yaml -> when@test */
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../../../var/log/test/app_errors.log', Level::Warning));

        return self::$testAppLogger = $logger;
    }
}
