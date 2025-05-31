<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\Attribute;

use Attribute;

#[Attribute]
final class AppDynamicParameter
{
    /**
     * @todo | UI | parameters | add some description and print in `show` command
     */
    public function __construct(public string $group, public ?string $name = null)
    {
    }
}
