<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit;

use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class ByBitApiServiceTestAbstract extends KernelTestCase
{
    use ByBitV5ApiTester;

    protected const ASSET_CATEGORY = AssetCategory::linear;
}
