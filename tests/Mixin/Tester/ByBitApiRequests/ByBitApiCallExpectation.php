<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Tester\ByBitApiRequests;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpClient\Response\MockResponse;

final readonly class ByBitApiCallExpectation
{
    public function __construct(
        public AbstractByBitApiRequest $expectedRequest,
        public MockResponse $resultResponse,
    ) {
    }
}