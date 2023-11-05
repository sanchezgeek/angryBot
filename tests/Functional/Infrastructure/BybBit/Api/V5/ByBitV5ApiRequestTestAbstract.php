<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Api\V5;

use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\API\Common\ByBitApiCallResult;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Mixin\JsonTrait;
use App\Tests\Stub\Request\SymfonyHttpClientStub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

use function array_merge;
use function assert;
use function date_create_immutable;
use function hash_hmac;
use function http_build_query;
use function sprintf;
use function strlen;

abstract class ByBitV5ApiRequestTestAbstract extends TestCase
{
    use JsonTrait;

    private const HOST = 'https://api-testnet.bybit.com';
    private const API_KEY = 'bybit-api-key';
    private const API_SECRET = 'bybit-api-secret';

    private const EXPECTED_RECV_WINDOW = '8000';
    private const EXPECTED_BAPI_SIGN_TYPE = '2';

    protected SymfonyHttpClientStub $httpClientStub;
    protected ByBitV5ApiClient $client;

    private int $apiRequestCallTimestamp;

    protected function setUp(): void
    {
        $this->httpClientStub = new SymfonyHttpClientStub(self::HOST);

        $clockMock = $this->createMock(ClockInterface::class);
        $clockMock->method('now')->willReturn($dateTime = date_create_immutable());
        $this->apiRequestCallTimestamp = $dateTime->getTimestamp();

        $this->client = new ByBitV5ApiClient(
            $this->httpClientStub,
            $clockMock,
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
        );
    }

    private const PRIVATE_REQUESTS = [
        GetPositionsRequest::class => true,
        PlaceOrderRequest::class => true,
    ];

    /**
     * @todo | Maybe need to revert to `expectedPrivateHeaders` and `expectedPublicHeaders`
     */
    protected function expectedPublicHeaders(AbstractByBitApiRequest $request): array
    {
        $params = $request->data();
        $method = $request->method();

        if ($method === Request::METHOD_POST) {
            $headers = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Content-Length' => (string)strlen(self::jsonEncode($params))
            ];
        } else {
            assert($method === Request::METHOD_GET, new RuntimeException(
                sprintf('Unknown request type (`%s` verb)', $method)
            ));

            $headers = [
                'Accept' => 'application/json',
            ];
        }

        return $headers;
    }

    protected function expectedPrivateHeaders(AbstractByBitApiRequest $request): array
    {
        $commonHeaders = $this->expectedPublicHeaders($request);

        $params = $request->data();
        $encoded = $request->method() === Request::METHOD_POST ? self::jsonEncode($params) : http_build_query($params);
        $timestamp = (string)($this->apiRequestCallTimestamp * 1000);
        $reqvWindow = self::EXPECTED_RECV_WINDOW;

        $apiKey = self::API_KEY;
        $apiSecret = self::API_SECRET;
        $paramsForSignature = $timestamp . $apiKey . $reqvWindow . $encoded;
        $signature = hash_hmac('sha256', $paramsForSignature, $apiSecret);

        return array_merge($commonHeaders, [
            'Accept' => 'application/json',
            'X-BAPI-API-KEY' => $apiKey,
            'X-BAPI-SIGN' => $signature,
            'X-BAPI-SIGN-TYPE' => self::EXPECTED_BAPI_SIGN_TYPE,
            'X-BAPI-TIMESTAMP' => $timestamp,
            'X-BAPI-RECV-WINDOW' => self::EXPECTED_RECV_WINDOW,
        ]);
    }

    protected function getFullRequestUrl(AbstractByBitApiRequest $request): string
    {
        return self::HOST . $request->url();
    }

    protected function okRequestResult(array $data): ByBitApiCallResult
    {
        return ByBitApiCallResult::ok($data);
    }
}
