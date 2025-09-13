<?php

declare(strict_types=1);

namespace App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter;

final class StringParameterReference extends AbstractMessageParameterReference
{
    public function __construct(string $name, ?string $title = null)
    {
        $title = $title ?? $name;

        return parent::__construct($name, $title);
    }

    public function resolveRawUserInput(mixed $userInput): string
    {
        if (!is_string($userInput)) {
            throw $this->validationError(['string'], $userInput);
        }

        return $userInput;
    }
}
