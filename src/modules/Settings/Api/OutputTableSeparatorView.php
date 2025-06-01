<?php

declare(strict_types=1);

namespace App\Settings\Api;

use JsonSerializable;

final readonly class OutputTableSeparatorView implements JsonSerializable
{
    public function jsonSerialize(): array
    {
        return [
            'type' => 'separator',
        ];
    }
}
