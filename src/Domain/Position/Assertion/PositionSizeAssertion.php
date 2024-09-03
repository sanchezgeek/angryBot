<?php

declare(strict_types=1);

namespace App\Domain\Position\Assertion;

use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;

class PositionSizeAssertion
{
    /**
     * @throws SizeCannotBeLessOrEqualsZeroException
     */
    public static function assert(float $size): void
    {
        if ($size <= 0) {
            throw new SizeCannotBeLessOrEqualsZeroException($size);
        }
    }
}