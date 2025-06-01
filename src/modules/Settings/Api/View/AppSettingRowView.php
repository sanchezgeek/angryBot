<?php

declare(strict_types=1);

namespace App\Settings\Api\View;

use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use JsonSerializable;

final readonly class AppSettingRowView implements JsonSerializable
{
    /**
     * @todo maybe some params SHOULD BE processed here
     */
    public function __construct(
        private bool $isFallbackValue,
        private string $displayKey,
        private AssignedSettingValue $assignedValue,
        private string $info,
//        private bool $isEditable,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'key' => $this->assignedValue->fullKey,
            'displayKey' => $this->displayKey,
            'info' => $this->info,
            'showValue' => (string) $this->assignedValue,
            'isDefault' => $this->assignedValue->isDefault(), // aka is-editable
            'isFallbackValue' => $this->isFallbackValue
        ];
    }
}
