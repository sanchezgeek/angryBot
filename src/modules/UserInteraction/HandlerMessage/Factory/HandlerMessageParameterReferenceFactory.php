<?php

declare(strict_types=1);

namespace App\modules\UserInteraction\HandlerMessage\Factory;

use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\AbstractMessageParameterReference;
use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\BooleanParameterReference;
use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\EnumParameterReference;
use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\StringParameterReference;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\UserInteraction\HandlerMessage\Dto\MessageParameter\SymbolParameterReference;
use BackedEnum;
use ReflectionParameter;
use RuntimeException;

final class HandlerMessageParameterReferenceFactory
{
    public function __construct(
        private SymbolProvider $symbolProvider
    ) {
    }

    public function makeReferenceFromParameterReflection(ReflectionParameter $reflectionParameter): AbstractMessageParameterReference
    {
        $parameterName = $reflectionParameter->getName();
        $parameterType = $reflectionParameter->getType()->getName();

        return match (true) {
            is_subclass_of($parameterType, BackedEnum::class) => new EnumParameterReference($parameterName, $parameterType),
            $parameterType === 'bool' => new BooleanParameterReference($parameterName),
            $parameterType === 'string' => new StringParameterReference($parameterName),
            $parameterType === SymbolInterface::class => new SymbolParameterReference($parameterName, $this->symbolProvider),
            default => throw new RuntimeException(sprintf('Unrecognized parameter %s of type %s', $parameterName, $parameterType))
        };
    }
}
