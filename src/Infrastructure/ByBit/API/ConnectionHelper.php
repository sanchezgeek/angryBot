<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

final class ConnectionHelper
{
    private const array CONNECTION_ERR_MESSAGES = [
        'timestamp or recv_window param',
        'Server Timeout',
        'Idle timeout reached',
        //        'Timestamp for this request is outside of the recvWindow',
    ];

    public static function isConnectionError(Throwable $error): bool
    {
        $exception = $error;
        while (($previous = $exception->getPrevious()) && ($previous instanceof TransportExceptionInterface)) {
            $exception = $previous;
        }

        if ($exception instanceof TransportExceptionInterface) {
            return true;
        }

        foreach (self::CONNECTION_ERR_MESSAGES as $expectedMessage) {
            if (str_contains($error->getMessage(), $expectedMessage)) {
                return true;
            }
        }

        return false;
    }
}
