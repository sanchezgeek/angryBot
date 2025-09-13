<?php

declare(strict_types=1);

namespace App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter;

use BackedEnum;

final class EnumParameterReference extends AbstractMessageParameterReference
{
    private string $enumClass;

    public function __construct(string $name, string $enumClass, ?string $title = null)
    {
        $title = $title ?? $name;

        $options = [];
        /** @var BackedEnum $enumClass */
        foreach ($enumClass::cases() as $case) {
            $options[$case->value] = $case->name;
        }

        $this->enumClass = $enumClass;

        return parent::__construct($name, $title, $options);
    }

    public function resolveRawUserInput(mixed $userInput): BackedEnum
    {
        /** @var BackedEnum $enumClass */
        $enumClass = $this->enumClass;

        return $enumClass::from($userInput);
    }
}
