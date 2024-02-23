<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Tester;

use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Tests\Functional\Infrastructure\BybBit\Service\ApiErrorTestCaseData;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\DataProvider\TestCaseAwareTest;
use App\Tests\Stub\Request\SymfonyHttpClientStub;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function assert;
use function count;
use function sprintf;

trait ByBitV5ApiTester
{
    use PositionSideAwareTest;
    use TestCaseAwareTest;

    public const DEFAULT_HOST = 'https://api-testnet.bybit.com';
    private const DEFAULT_API_KEY = 'bybit-api-key';
    private const DEFAULT_API_SECRET = 'bybit-api-secret';

    private ?SymfonyHttpClientStub $httpClientStub = null;

    /** @var AbstractByBitApiRequest[] */
    private array $expectedApiRequestsAfterTest = [];

    public function initializeApiClient(
        string $host = self::DEFAULT_HOST,
        string $apiKey = self::DEFAULT_API_KEY,
        string $apiSecret = self::DEFAULT_API_SECRET,
    ): ByBitV5ApiClient {
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

    private function getHttClientStub(): SymfonyHttpClientStub
    {
        return $this->httpClientStub ?? ($this->httpClientStub = self::getContainer()->get(HttpClientInterface::class));
    }

    protected function getFullRequestUrl(AbstractByBitApiRequest $request): string
    {
        $apiHost = $this->getHttClientStub()->getBaseUri();
        return $apiHost . $request->url();
    }

    protected function matchGet(AbstractByBitApiRequest $expectedRequest, MockResponse $resultResponse, bool $needTrackRequestCallToFurtherCheck = true): void
    {
        $requestUrl = $this->getFullRequestUrl($expectedRequest);
        $this->getHttClientStub()->matchGet($requestUrl, $expectedRequest->data(), $resultResponse, $needTrackRequestCallToFurtherCheck);

        if ($needTrackRequestCallToFurtherCheck) {
            $this->expectedApiRequestsAfterTest[] = $expectedRequest;
        }
    }

    protected function matchPost(AbstractByBitApiRequest $expectedRequest, MockResponse $resultResponse, bool $needTrackRequestCallToFurtherCheck = true): void
    {
        $requestUrl = $this->getFullRequestUrl($expectedRequest);
        $this->getHttClientStub()->matchPost($requestUrl, $resultResponse, $expectedRequest->data(), $needTrackRequestCallToFurtherCheck);

        if ($needTrackRequestCallToFurtherCheck) {
            $this->expectedApiRequestsAfterTest[] = $expectedRequest;
        }
    }

    /**
     * @after
     */
    protected function assertResultHttpClientCalls(): void
    {
        $actualRequestCalls = $this->getHttClientStub()->getRequestCalls();
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
                    sprintf('Unknown request type (`%s` verb)', $expectedRequest->method()),
                ));

                self::assertSame($expectedRequest->method(), $actualRequestCall->method);
                self::assertSame($this->getFullRequestUrl($expectedRequest), $actualRequestCall->url);
                self::assertNull($actualRequestCall->body);
                self::assertSame($expectedRequest->data(), $actualRequestCall->params);
            }
        }
    }

    protected static function unknownV5ApiErrorException(
        string $requestUrl,
        ByBitV5ApiError $error,
    ): UnknownByBitApiErrorException {
        return new UnknownByBitApiErrorException($error->code(), $error->msg(), sprintf('Make `%s` request', $requestUrl));
    }

    protected static function unexpectedV5ApiErrorException(
        string $requestUrl,
        ByBitV5ApiError $error,
        string $serviceMethod,
    ): UnexpectedApiErrorException {
        return self::unexpectedApiErrorError($requestUrl, $error->code(), $error->msg(), $serviceMethod);
    }

    protected static function unexpectedApiErrorError(
        string $requestUrl,
        int $code,
        string $message,
        string $serviceMethod,
    ): UnexpectedApiErrorException {
        return new UnexpectedApiErrorException($code, $message, sprintf('%s | make `%s`', $serviceMethod, $requestUrl));
    }

    /**
     * @return ApiErrorTestCaseData[]
     */
    protected function commonFailedApiCallCases(string $requestUrl): array
    {
        return [
            ApiErrorTestCaseData::knownApiError(ApiV5Errors::ApiRateLimitReached, $msg = 'Api rate limit', new ApiRateLimitReached($msg)),
            ApiErrorTestCaseData::unknownApiError($requestUrl),
        ];
    }
}
