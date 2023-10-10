<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService;

use App\Infrastructure\ByBit\API\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

use function sprintf;

abstract class ByBitLinearExchangeServiceTestAbstract extends KernelTestCase
{
    use ByBitV5ApiTester;

    protected const ASSET_CATEGORY = AssetCategory::linear;
    protected const WORKER_DEBUG_HASH = '123456';

    protected ByBitLinearExchangeService $service;

    protected function setUp(): void
    {
        $this->service = new ByBitLinearExchangeService(
            $this->initializeApiClient(),
            self::WORKER_DEBUG_HASH
        );
    }

    /**
     * @todo | apiV5 | tests | trait
     */
    protected static function expectedUnknownApiErrorException(
        string $requestUrl,
        ApiErrorInterface $error,
        string $in,
    ): Throwable {
        return new RuntimeException(
            sprintf('%s | make `%s`: unknown errCode %d (%s)', $in, $requestUrl, $error->code(), $error->desc())
        );
    }
}
