<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Exception;

use Exception;

use function sprintf;

final class UnexpectedApiErrorException extends Exception
{
    public function __construct(int $code, string $message, string $context)
    {
        parent::__construct(
            sprintf('%s | Got unexpected API errCode %d (%s)', $context, $code, $message)
        );
    }
}
