<?php

declare(strict_types=1);

namespace App\Info\Application\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class RequiredAttr
{
}
