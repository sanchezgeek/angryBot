<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Orders\Dto;

final class CreatedIncGridInfo implements \JsonSerializable
{
    public function __construct(public readonly array $info)
    {
    }

    public function jsonSerialize(): mixed
    {
        return $this->info;
    }
}
