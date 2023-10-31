<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5;

use App\Clock\ClockInterface;
use App\Helper\Json;
use App\Infrastructure\ByBit\API\Common\ByBitApiCallResult;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        private string $host,
        private string $apiKey,
        private string $secretKey,
    ) {
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     */
    public function send(AbstractByBitApiRequest $request): ByBitApiCallResult
    {
        try {
            $result = $this->doSend($request);
        } catch (UnknownByBitApiErrorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $msg = sprintf(
                'ByBitV5ApiClient::send | Got %s exception (%s) when do `%s` request call.',
                get_class($e),
                $e->getMessage(),
                $this->host . $request->url(),
            );

            throw new RuntimeException($msg, 0, $e);
        }

        if (!$result->isSuccess()) {
            $error = $result->error();
            match ($error->code()) {
                ApiV5Errors::ApiRateLimitReached->value => throw new ApiRateLimitReached($error->msg()),
                default => null
            };
        }

        return $result;
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws UnknownByBitApiErrorException
     */
    private function doSend(AbstractByBitApiRequest $request): ByBitApiCallResult
    {
        $url = $this->host . $request->url();
        $response = $this->httpClient->request($request->method(), $url, $this->getOptions($request));

        $responseBody = $response->toArray();

        if (($retCode = $responseBody['retCode'] ?? null) !== 0) {
            $error = ApiV5Errors::tryFrom($retCode);
            if (!$error) {
                throw new UnknownByBitApiErrorException($retCode, $responseBody['retMsg'], sprintf('Make `%s` request', $request->url()));
            }

            return ByBitApiCallResult::err(
                ByBitV5ApiError::knownError($error, $responseBody['retMsg'])
            );
        }

        if (!($result = $responseBody['result'] ?? null)) {
            throw BadApiResponseException::common($request, 'retCode = 0, but `result` key not found');
        }

        if (!is_array($result)) {
            throw BadApiResponseException::invalidItemType($request, '`result`', $result, 'array');
        }

        return ByBitApiCallResult::ok($result);
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

        if ($request->isPrivateRequest()) {
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
