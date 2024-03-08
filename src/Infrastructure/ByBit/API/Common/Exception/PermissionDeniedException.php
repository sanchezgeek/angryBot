<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\Common\Exception;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Exception;

use function lcfirst;
use function sprintf;

final class PermissionDeniedException extends Exception
{
    public function __construct(AbstractByBitApiRequest $req)
    {
        $message = sprintf('Permission denied (`%s %s`)', $req->method(), $req->url());

        parent::__construct($message);
    }
}
