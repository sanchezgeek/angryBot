<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\Exception;

use function sprintf;

final class UnknownApiErrorException extends AbstractByBitApiException
{
    public function __construct(int $code, string $message, string $context)
    {
        parent::__construct(
            sprintf('%s: got unknown errCode %d (%s)', $context, $code, $message)
        );
    }
}
