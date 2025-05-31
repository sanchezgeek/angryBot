<?php

declare(strict_types=1);

namespace App\Bot\Domain\ValueObject\Order;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;

enum OrderType: string
{
    case Stop = 'Stop';
    case Add = 'Add';

    const array TITLE = [
        self::Stop->value => 'Stop',
        self::Add->value => 'Buy'
    ];

    public function title(): string
    {
        return self::TITLE[$this->value];
    }

    public static function fromEntity(Stop|BuyOrder $order): self
    {
        return match (true) {
            $order instanceof BuyOrder => self::Add,
            $order instanceof Stop => self::Stop,
        };
    }
}
