<?php

declare(strict_types=1);

namespace App\modules\UserInteraction\HandlerMessage\Factory;

use App\modules\UserInteraction\HandlerMessage\Dto\HandlerMessageReference;
use ReflectionClass;
use ReflectionException;

final class HandlerMessageReferenceFactory
{
    public function __construct(
        private HandlerMessageParameterReferenceFactory $handlerMessageParameterReferenceFactory
    ) {
    }

    /**
     * @throws ReflectionException
     */
    public function fromTaskEntryClass(string $taskEntryClass): HandlerMessageReference
    {
        $parameters = [];
        foreach (new ReflectionClass($taskEntryClass)->getConstructor()->getParameters() as $parameter) {
            $parameters[] = $this->handlerMessageParameterReferenceFactory->makeReferenceFromParameterReflection($parameter);
        }

        return new HandlerMessageReference($taskEntryClass, $parameters);
    }
}
