<?php

declare(strict_types=1);

namespace App\modules\UserInteraction\HandlerMessage\Factory;

use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\AbstractMessageParameterReference;
use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\BooleanParameterReference;
use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\EnumParameterReference;
use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\StringParameterReference;
use BackedEnum;
use ReflectionParameter;
use RuntimeException;

final class HandlerMessageParameterReferenceFactory
{
    public static function makeReferenceFromParameterReflection(ReflectionParameter $reflectionParameter): AbstractMessageParameterReference
    {
        $parameterName = $reflectionParameter->getName();
        $parameterType = $reflectionParameter->getType()->getName();

        return match (true) {
            is_subclass_of($parameterType, BackedEnum::class) => new EnumParameterReference($parameterName, $parameterType),
            $parameterType === 'bool' => new BooleanParameterReference($parameterName),
            $parameterType === 'string' => new StringParameterReference($parameterName),
            default => throw new RuntimeException(sprintf('Unrecognized parameter %s of type %s', $parameterName, $parameterType))
        };
    }
}
