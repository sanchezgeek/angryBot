<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Tests\Utils\TestData\TestCaseDataBase;
use App\Trading\Domain\Symbol\SymbolInterface;

class ApiTestCaseData extends TestCaseDataBase
{
    private function __construct(AssetCategory $category, SymbolInterface $symbol)
    {
        parent::__construct(['category' => $category, 'symbol' => $symbol]);
    }

    public static function linearBtcUsdt(): self
    {
        return new static(AssetCategory::linear, SymbolEnum::BTCUSDT);
    }
}
