<?php

declare(strict_types=1);

namespace App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter;

use InvalidArgumentException;

final class BooleanParameterReference extends AbstractMessageParameterReference
{
    public function __construct(string $name, ?string $title = null)
    {
        $title = $title ?? $name;

        return parent::__construct($name, $title, [true => 'true', false => 'false']);
    }

    public function resolveRawUserInput(mixed $userInput): bool
    {
        if (is_bool($userInput)) {
            return $userInput;
        }

        if (is_int($userInput)) {
            assert(in_array($userInput, [0, 1], true), $this->validationError(['0', '1'], $userInput));
            return (bool)$userInput;
        }

        if (is_string($userInput)) {
            assert(in_array($userInput, ['true', 'false'], true), $this->validationError(['false', 'true'], $userInput));
            return match ($userInput) {
                'true' => true,
                'false' => false
            };
        }

        throw new InvalidArgumentException(sprintf('Value must be of type bool or be one of ["false", "true", 0, 1]'));
    }
}
