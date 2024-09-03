<?php

declare(strict_types=1);

namespace App\Domain\Position\Exception;

use Exception;

use function sprintf;

class SizeCannotBeLessOrEqualsZeroException extends Exception
{
    public function __construct(float $size)
    {
        parent::__construct(sprintf('%s: size must be greater than zero. "%s" provided', __CLASS__, $size));
    }
}