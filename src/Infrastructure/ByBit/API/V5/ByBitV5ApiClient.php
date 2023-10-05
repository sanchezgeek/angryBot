<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5;

use App\Clock\ClockInterface;
use App\Helper\Json;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Result\ByBitApiCallResult;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function array_merge;
use function get_class;
use function gettype;
use function hash_hmac;
use function http_build_query;
use function is_array;
use function sprintf;

/**
 * @see SendPrivateV5ApiRequestTest
 * @see SendPublicV5ApiRequestTest
 *
 * @see SendGetTickersV5ApiRequestTest
 * @see SendGetPositionsV5ApiRequestTest
 * @see SendPlaceBuyOrderV5ApiRequestTest
 */
final readonly class ByBitV5ApiClient implements ByBitApiClientInterface
{
    private const BAPI_RECOMMENDED_RECV_WINDOW = '5000';
    private const BAPI_SIGN_TYPE = '2';

    private const PRIVATE_REQUESTS = [
        GetPositionsRequest::class => true,
        PlaceOrderRequest::class => true,
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        private string $host,
        private string $apiKey,
        private string $secretKey,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function send(AbstractByBitApiRequest $request): ByBitApiCallResult
    {
        try {
            $url = $this->host . $request->url();

            $response = $this->httpClient->request($request->method(), $url, $this->getOptions($request));
            $responseBody = $response->toArray();

            if (($retCode = $responseBody['retCode'] ?? null) !== 0) {
                if (!($error = ApiV5Error::tryFrom($retCode))) {
                    throw new \RuntimeException(sprintf('Received unknown retCode (%d)', $retCode));
                }

                return ByBitApiCallResult::err($error);
            }

            if (!($result = $responseBody['result'] ?? null)) {
                throw new LogicException(sprintf('%s: received response with retCode = 0, but without `result` key. Please check API contract.', __METHOD__));
            }

            if (!is_array($result)) {
                throw new LogicException(sprintf('%s: received `result` must be type of array (%s given).', __METHOD__, gettype($result)));
            }

            return ByBitApiCallResult::ok($result);
        } catch (\Throwable $e) {
            var_dump(sprintf('%s: get %s exception (%s) when do %s request call.', __METHOD__, get_class($e), $e->getMessage(), $url));
            throw $e;
        }
    }

    private function getOptions(AbstractByBitApiRequest $request): array
    {
        if (($isPost = ($method = $request->method()) === Request::METHOD_POST)) {
            $options = [
                'body' => Json::encode($request->data()),
                'headers' => [
                    'Accept: application/json',
                    'Content-Type: application/json'
                ],
            ];
        } else {
            $method !== Request::METHOD_GET && throw new RuntimeException(
                sprintf('Unknown request type (`%s` verb)', $method)
            );

            $options = [
                'query' => $request->data(),
                'headers' => ['Accept: application/json'],
            ];
        }

        if (isset(self::PRIVATE_REQUESTS[get_class($request)])) {
            $timestamp = $this->clock->now()->getTimestamp() * 1000;
            $reqvWindow = self::BAPI_RECOMMENDED_RECV_WINDOW;
            $encodedParams = $isPost ? Json::encode($request->data()) : http_build_query($request->data());
            $paramsForSignature = $timestamp . $this->apiKey . $reqvWindow . $encodedParams;
            $signature = hash_hmac('sha256', $paramsForSignature, $this->secretKey);

            $options['headers'] = array_merge($options['headers'], [
                'X-BAPI-API-KEY' => $this->apiKey,
                'X-BAPI-SIGN' => $signature,
                'X-BAPI-SIGN-TYPE' => self::BAPI_SIGN_TYPE,
                'X-BAPI-TIMESTAMP' => $timestamp,
                'X-BAPI-RECV-WINDOW' => self::BAPI_RECOMMENDED_RECV_WINDOW,
            ]);
        }

        return $options;
    }
}
