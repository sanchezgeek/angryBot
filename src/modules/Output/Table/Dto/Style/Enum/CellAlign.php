<?php

declare(strict_types=1);

namespace App\Output\Table\Dto\Style\Enum;

enum CellAlign: string
{
    case LEFT = 'left';
    case CENTER = 'center';
    case RIGHT = 'right';
}
