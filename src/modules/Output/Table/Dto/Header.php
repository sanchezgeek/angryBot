<?php

declare(strict_types=1);

namespace App\Output\Table\Dto;

final readonly class Header
{
    public string $name;
    public string $caption;

    public function __construct(
        string $name,
        string $caption = null,
    ) {
        $this->name = $name;
        $this->caption = $caption ?? $this->name;
    }
}
