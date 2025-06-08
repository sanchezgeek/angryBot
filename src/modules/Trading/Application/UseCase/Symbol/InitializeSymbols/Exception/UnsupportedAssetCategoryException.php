<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception;

final class UnsupportedAssetCategoryException extends \Exception
{
    public function __construct(string $assetContractType, array $availableContractTypes)
    {
        parent::__construct(
            sprintf('Unknown contractType "%s". Available contract types: "%s"', $assetContractType, implode('", "', $availableContractTypes))
        );
    }

    public function __sleep(): array
    {
        return [
            'message',
            'code',
        ];
    }
}
