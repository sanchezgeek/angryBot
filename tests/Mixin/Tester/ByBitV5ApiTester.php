<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Tester;

use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient;
use App\Tests\Stub\Request\SymfonyHttpClientStub;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function count;
use function sprintf;

trait ByBitV5ApiTester
{
    private const DEFAULT_HOST = 'https://api-testnet.bybit.com';
    private const DEFAULT_API_KEY = 'bybit-api-key';
    private const DEFAULT_API_SECRET = 'bybit-api-secret';

    private string $apiHost;

    private SymfonyHttpClientStub $httpClientStub;

    /**
     * @var AbstractByBitApiRequest[]
     */
    private array $expectedApiRequestsAfterTest = [];

    public function initializeApiClient(
        string $host = self::DEFAULT_HOST,
        string $apiKey = self::DEFAULT_API_KEY,
        string $apiSecret = self::DEFAULT_API_SECRET
    ): ByBitV5ApiClient {
        $this->apiHost = $host;

        $clockMock = $this->createMock(ClockInterface::class);
        $clockMock->method('now')->willReturn(new DateTimeImmutable());

        $this->httpClientStub = new SymfonyHttpClientStub($host);

        return new ByBitV5ApiClient(
            $this->httpClientStub,
            $clockMock,
            $host,
            $apiKey,
            $apiSecret,
        );
    }

    protected function getFullRequestUrl(AbstractByBitApiRequest $request): string
    {
        return $this->apiHost . $request->url();
    }

    protected function matchGet(AbstractByBitApiRequest $request, MockResponse $resultResponse): void
    {
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchGet($requestUrl, $request->data(), $resultResponse);

        $this->expectedApiRequestsAfterTest[] = $request;
    }

    protected function matchPost(AbstractByBitApiRequest $request, MockResponse $resultResponse): void
    {
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchPost($requestUrl, $resultResponse);

        $this->expectedApiRequestsAfterTest[] = $request;
    }

    /**
     * @after
     */
    protected function assertResultHttpClientCalls(): void
    {
        $actualRequestCalls = $this->httpClientStub->getRequestCalls();
        self::assertCount(count($this->expectedApiRequestsAfterTest), $actualRequestCalls);

        foreach ($this->expectedApiRequestsAfterTest as $key => $expectedRequest) {
            $actualRequestCall = $actualRequestCalls[$key];
            if ($expectedRequest->method() === Request::METHOD_POST) {
                self::assertSame($expectedRequest->method(), $actualRequestCall->method);
                self::assertSame($this->getFullRequestUrl($expectedRequest), $actualRequestCall->url);
                self::assertNull($actualRequestCall->params);
                self::assertSame($expectedRequest->data(), $actualRequestCall->body);
            } else {
                assert($expectedRequest->method() === Request::METHOD_GET, new RuntimeException(
                    sprintf('Unknown request type (`%s` verb)', $expectedRequest->method())
                ));

                self::assertSame($expectedRequest->method(), $actualRequestCall->method);
                self::assertSame($this->getFullRequestUrl($expectedRequest), $actualRequestCall->url);
                self::assertNull($actualRequestCall->body);
                self::assertSame($expectedRequest->data(), $actualRequestCall->params);
            }
        }
    }
}
