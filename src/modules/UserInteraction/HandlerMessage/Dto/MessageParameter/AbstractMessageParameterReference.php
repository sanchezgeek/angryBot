<?php

declare(strict_types=1);

namespace App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter;

use InvalidArgumentException;

abstract class AbstractMessageParameterReference
{
    protected function __construct(
        public string $argName,
        public string $title,
        public ?array $options = null,
    ) {
    }

    abstract public function resolveRawUserInput(mixed $userInput): mixed;

    protected function validationError(array $allowedValues, mixed $givenValue): InvalidArgumentException
    {
        return new InvalidArgumentException(
            sprintf('`%s`%s value must be one of [%s] (%s given)',
                $this->argName,
                $this->argName !== $this->title ? sprintf(' (%s)', $this->title) : '',
                implode(', ', $allowedValues),
                $givenValue
            )
        );
    }
}
