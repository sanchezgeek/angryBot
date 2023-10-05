<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionService;

use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient;
use App\Infrastructure\ByBit\ByBitLinearPositionService;
use App\Tests\Stub\Request\SymfonyHttpClientStub;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function count;
use function sprintf;

abstract class ByBitLinearPositionServiceTestAbstract extends KernelTestCase
{
    private const HOST = 'https://api-testnet.bybit.com';
    private const API_KEY = 'bybit-api-key';
    private const API_SECRET = 'bybit-api-secret';

    protected SymfonyHttpClientStub $httpClientStub;
    protected ByBitLinearPositionService $service;

    /**
     * @var AbstractByBitApiRequest[]
     */
    private array $expectedApiRequestsAfterTest = [];

    protected function setUp(): void
    {
        $clockMock = $this->createMock(ClockInterface::class);
        $clockMock->method('now')->willReturn(new DateTimeImmutable());

        $this->httpClientStub = new SymfonyHttpClientStub(self::HOST);

        $this->service = new ByBitLinearPositionService(
            // @todo | tests | create client with factory (for all tests)
            // @todo | tests | make some kind of mixin to work with api
            new ByBitV5ApiClient(
                $this->httpClientStub,
                $clockMock,
                self::HOST,
                self::API_KEY,
                self::API_SECRET,
            )
        );
    }

    /**
     * @todo | tests | make some kind of mixin to work with api
     */
    protected function getFullRequestUrl(AbstractByBitApiRequest $request): string
    {
        return self::HOST . $request->url();
    }

    /**
     * @todo | tests | make some kind of mixin to work with api
     */
    protected function matchGet(AbstractByBitApiRequest $request, MockResponse $resultResponse): void
    {
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchGet($requestUrl, $request->data(), $resultResponse);

        $this->expectedApiRequestsAfterTest[] = $request;
    }

    /**
     * @todo | tests | make some kind of mixin to work with api
     */
    protected function matchPost(AbstractByBitApiRequest $request, MockResponse $resultResponse): void
    {
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchPost($requestUrl, $resultResponse);

        $this->expectedApiRequestsAfterTest[] = $request;
    }

    /**
     * @after
     *
     * @todo | tests | make some kind of mixin to work with api
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
