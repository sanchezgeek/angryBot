<?php

declare(strict_types=1);

namespace App\Output\Table\Dto\Style\Enum;

enum Color
{
    case DEFAULT;
    case GREEN;
    case BRIGHT_GREEN;
    case YELLOW;
    case GRAY;
    case RED;
    case BRIGHT_RED;
    case CYAN;

    public function isDefault(): bool
    {
        return $this === self::DEFAULT;
    }
}
