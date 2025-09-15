<?php

declare(strict_types=1);

namespace App\Info\Application\Attribute;

use Attribute;
use BackedEnum;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class AutoDependencyAttribute
{
    /**
     * @todo specify array of parameters to evaluate after edit
     */
    public function __construct(
        public BackedEnum|string $dependsOn,
        public mixed $resultValue,
    ) {
    }
}
