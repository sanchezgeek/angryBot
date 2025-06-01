<?php

declare(strict_types=1);

namespace App\Settings\Api\View;

use JsonSerializable;

final readonly class AppSettingGroupView implements JsonSerializable
{
    private array $items;

    public function __construct(
        private string $caption,
        AppSettingRowView ...$items
    ) {
        $this->items = $items;
    }

    public function jsonSerialize(): array
    {
        return [
            'caption' => $this->caption,
            'items' => $this->items
        ];
    }
}
