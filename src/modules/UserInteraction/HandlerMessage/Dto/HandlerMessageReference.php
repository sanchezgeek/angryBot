<?php

declare(strict_types=1);

namespace App\modules\UserInteraction\HandlerMessage\Dto;

use App\Helper\OutputHelper;
use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\AbstractMessageParameterReference;
use ReflectionClass;
use ReflectionException;

final readonly class HandlerMessageReference
{
    /** @var AbstractMessageParameterReference[] */
    public array $parameters;

    /**
     * @param AbstractMessageParameterReference[] $parameters
     */
    public function __construct(
        public string $className,
        array $parameters
    ) {
        $this->setParameters(...$parameters);
    }

    private function setParameters(AbstractMessageParameterReference ...$parameters): void
    {
        $this->parameters = $parameters;
    }

    public function shortName(): string
    {
        return OutputHelper::shortClassName($this->className);
    }

    /**
     * @throws ReflectionException
     */
    public function makeTaskEntryFromUserInput(array $input): object
    {
        $ref = new ReflectionClass($this->className);

        return $ref->newInstance(...$input);
    }
}
