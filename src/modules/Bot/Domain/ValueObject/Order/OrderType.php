<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject\Order;

enum OrderType: string
{
    case Stop = 'Stop';
    case Add = 'Add';

    const TITLE = [
        self::Stop->value => 'Stop',
        self::Add->value => 'Buy'
    ];

    public function title(): string
    {
        return self::TITLE[$this->value];
    }
}
