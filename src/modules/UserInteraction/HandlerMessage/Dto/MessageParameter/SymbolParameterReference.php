<?php

declare(strict_types=1);

namespace App\UserInteraction\HandlerMessage\Dto\MessageParameter;

use App\modules\UserInteraction\HandlerMessage\Dto\MessageParameter\AbstractMessageParameterReference;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Domain\Symbol\SymbolInterface;

final class SymbolParameterReference extends AbstractMessageParameterReference
{
    public function __construct(string $name, private SymbolProvider $symbolProvider, ?string $title = null)
    {
        $title = $title ?? $name;

        return parent::__construct($name, $title);
    }

    public function resolveRawUserInput(mixed $userInput): ?SymbolInterface
    {
        return $userInput ? $this->symbolProvider->getOrInitialize($userInput) : null;
    }
}
