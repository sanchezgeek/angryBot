<?php

declare(strict_types=1);

namespace App\modules\UserInteraction\HandlerMessage\Factory;

use App\modules\UserInteraction\HandlerMessage\Dto\HandlerMessageReference;
use ReflectionClass;
use ReflectionException;

final class HandlerMessageReferenceFactory
{
    /**
     * @throws ReflectionException
     */
    public static function fromTaskEntryClass(string $taskEntryClass): HandlerMessageReference
    {
        $parameters = [];
        foreach (new ReflectionClass($taskEntryClass)->getConstructor()->getParameters() as $parameter) {
            $parameters[] = HandlerMessageParameterReferenceFactory::makeReferenceFromParameterReflection($parameter);
        }

        return new HandlerMessageReference($taskEntryClass, $parameters);
    }
}
