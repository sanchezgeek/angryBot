<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract\Enum;

enum SoundLength: string
{
    case DEFAULT = 'default';
    case Short = 'short-sound';
}
