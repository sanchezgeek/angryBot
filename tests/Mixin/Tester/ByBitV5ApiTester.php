<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Tester;

use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Tests\Functional\Infrastructure\BybBit\Service\ApiErrorTestCaseData;
use App\Tests\Functional\Infrastructure\BybBit\Service\Trade\ByBitOrderServiceTestData;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\DataProvider\TestCaseAwareTest;
use App\Tests\Mock\Response\ByBitV5Api\ErrorResponseFactory;
use App\Tests\Stub\Request\SymfonyHttpClientStub;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function count;
use function iterator_to_array;
use function sprintf;

trait ByBitV5ApiTester
{
    use PositionSideAwareTest;
    use TestCaseAwareTest;

    public const DEFAULT_HOST = 'https://api-testnet.bybit.com';
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

    protected function matchGet(AbstractByBitApiRequest $expectedRequest, MockResponse $resultResponse): void
    {
        $requestUrl = $this->getFullRequestUrl($expectedRequest);
        $this->httpClientStub->matchGet($requestUrl, $expectedRequest->data(), $resultResponse);

        $this->expectedApiRequestsAfterTest[] = $expectedRequest;
    }

    protected function matchPost(AbstractByBitApiRequest $expectedRequest, MockResponse $resultResponse): void
    {
        $requestUrl = $this->getFullRequestUrl($expectedRequest);
        $this->httpClientStub->matchPost($requestUrl, $resultResponse);

        $this->expectedApiRequestsAfterTest[] = $expectedRequest;
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

    protected static function unknownV5ApiErrorException(
        string $requestUrl,
        ByBitV5ApiError $error,
    ): UnknownByBitApiErrorException {
        return new UnknownByBitApiErrorException($error->code(), $error->msg(), sprintf('Make `%s` request', $requestUrl));
    }

    protected static function unexpectedV5ApiErrorException(
        string $requestUrl,
        ByBitV5ApiError $error,
        string $serviceMethod
    ): UnexpectedApiErrorException {
        return new UnexpectedApiErrorException($error->code(), $error->msg(), sprintf('%s | make `%s`', $serviceMethod, $requestUrl));
    }

    protected static function unexpectedException(
        string $requestUrl,
        int $code,
        string $message,
        string $serviceMethod
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
            ApiErrorTestCaseData::unknownApiError($requestUrl)
        ];
    }
}
