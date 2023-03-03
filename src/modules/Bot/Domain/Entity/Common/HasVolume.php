<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait HasVolume
{
    public function addVolume(float $volume): self
    {
        $this->volume += $volume;

        return $this;
    }
}
