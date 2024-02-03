<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\Common\Exception;

use Exception;

final class ApiRateLimitReached extends Exception
{
}
