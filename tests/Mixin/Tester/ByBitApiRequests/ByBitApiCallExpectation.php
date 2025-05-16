<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Tester\ByBitApiRequests;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ByBitApiCallExpectation
{
    public function __construct(
        public readonly AbstractByBitApiRequest $expectedRequest,
        public readonly MockResponse $resultResponse,
        /** @internal  */
        private bool $trackPositionCallToFurtherCheck = true,
        public ?string $requestKey = null
    ) {
    }

    public function isNeedTrackRequestCallToFurtherCheck(): bool
    {
        return $this->trackPositionCallToFurtherCheck;
    }

    public function setNoNeedToTrackRequestCallToFurtherCheck(): self
    {
        $this->trackPositionCallToFurtherCheck = false;

        return $this;
    }

    public function setRequestKey(string $requestKey): self
    {
        $this->requestKey = $requestKey;
        return $this;
    }
}
