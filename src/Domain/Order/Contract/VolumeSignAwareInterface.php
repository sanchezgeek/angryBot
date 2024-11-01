<?php

declare(strict_types=1);

namespace App\Domain\Order\Contract;

interface VolumeSignAwareInterface
{
    public function signedVolume(): float;
}
