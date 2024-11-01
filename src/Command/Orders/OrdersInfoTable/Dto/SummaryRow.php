<?php

declare(strict_types=1);

namespace App\Command\Orders\OrdersInfoTable\Dto;

class SummaryRow
{
    public function __construct(public string $caption, public mixed $content)
    {
    }
}
