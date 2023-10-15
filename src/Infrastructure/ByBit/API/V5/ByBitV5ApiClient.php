<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5;

use App\Clock\ClockInterface;
use App\Helper\Json;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Exception\AbstractByBitApiException;
use App\Infrastructure\ByBit\API\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Exception\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\API\Exception\UnknownApiErrorException;
use App\Infrastructure\ByBit\API\Result\ByBitApiCallResult;
use App\Infrastructure\ByBit\API\Result\CommonApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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

    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        private string $host,
        private string $apiKey,
        private string $secretKey,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function send(AbstractByBitApiRequest $request): ByBitApiCallResult
    {
        try {
            $result = $this->doSend($request);

            if (!$result->isSuccess()) {
                match ($result->error()) {
                    ApiV5Error::ApiRateLimitReached => throw new ApiRateLimitReached(),
                    ApiV5Error::MaxActiveCondOrdersQntReached => throw new MaxActiveCondOrdersQntReached(),
                };
            }

            return $result;
        } catch (AbstractByBitApiException $e) {
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
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws UnknownApiErrorException
     */
    private function doSend(AbstractByBitApiRequest $request): ByBitApiCallResult
    {
        $url = $this->host . $request->url();

        $response = $this->httpClient->request($request->method(), $url, $this->getOptions($request));
        $responseBody = $response->toArray();

        if (($retCode = $responseBody['retCode'] ?? null) !== 0) {
            $error = ApiV5Error::tryFrom($retCode);
            if (!$error) {
                throw new UnknownApiErrorException($retCode, $responseBody['retMsg'], sprintf('Make `%s` request', $url));
            }

            return ByBitApiCallResult::err($error);
        }

        if (!($result = $responseBody['result'] ?? null)) {
            throw new RuntimeException('Received response with retCode = 0, but `result` key not found in response. Check API contract.');
        }

        if (!is_array($result)) {
            throw new RuntimeException(sprintf('Received `result` must be type of array (%s given).', gettype($result)));
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
