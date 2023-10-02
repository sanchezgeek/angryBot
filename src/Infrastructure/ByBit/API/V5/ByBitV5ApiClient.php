<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5;

use App\Clock\ClockInterface;
use App\Helper\Json;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_merge;
use function get_class;
use function hash_hmac;
use function http_build_query;
use function sprintf;

/**
 * @see \App\Tests\Functional\Infrastructure\BybBit\V5Api\Market\GetTickersV5ApiRequestTest
 * @see \App\Tests\Functional\Infrastructure\BybBit\V5Api\Position\GetPositionsV5ApiRequestTest
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

    public function send(AbstractByBitApiRequest $request): array
    {
        try {
            $response = $this->httpClient->request(
                $request->method(),
                $this->host . $request->url(),
                $this->getOptions($request)
            );

            return $response->toArray();
        } catch (TransportExceptionInterface $e) {
            var_dump($e->getMessage());die;
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
