<?php

declare(strict_types=1);

namespace App\Tests\Stub\Request;

use App\Tests\Mixin\JsonTrait;
use Closure;
use InvalidArgumentException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function addcslashes;
use function array_keys;
use function array_merge;
use function array_unshift;
use function explode;
use function http_build_query;
use function is_array;
use function is_string;
use function json_encode;
use function parse_str;
use function preg_match;
use function str_contains;
use function uuid_create;
use function var_dump;

/**
 * @see \App\Tests\Unit\Stub\SymfonyHttpClientStubTest
 */
class SymfonyHttpClientStub extends MockHttpClient
{
    use JsonTrait;

    private ?string $baseUri;
    private ResponseInterface $defaultResponse;

    /**
     * @var array<callable>
     */
    private array $matchers = [];

    /**
     * @var RequestCall[]
     */
    private array $requestCalls = [];

    public function __construct(?string $baseUri = null)
    {
        parent::__construct($this->handler(), $baseUri);

        $this->baseUri = $baseUri;
        $this->defaultResponse = new MockResponse('', ['http_code' => 404]);
    }

    private function handler(): Closure
    {
        return function (string $method, string $url, array $options) {
            foreach ($this->matchers as $key => $matcher) {
                if (($result = $matcher($method, $url, $options)) !== null) {
                    break;
                }
            }

            $result = $result ?? null;

            /** @see self::match() return value of closure */
            if (is_array($result)) {
                [$response, $registerRequestCall] = $result;

                if ($registerRequestCall) {
                    $this->registerCall($key, $method, $url, $options);
                }

                return $response;
            }

            return $this->defaultResponse;
        };
    }

    /**
     * @param array<string, string> $params
     *
     * @return string Registered request `key`
     */
    public function matchGet(string $url, array $params, ResponseInterface $response, bool $registerRequestCall = true, string $key = null): string
    {
        $key ??= uuid_create() . '-GET-api-call';

        $url = $params ? $url . '?' . http_build_query($params) : $url;
        $urlRegexp = addcslashes($url, '?+.*');

        // @todo | headers
        $this->matchMethodAndUrl(Request::METHOD_GET, $urlRegexp, $response, [], $registerRequestCall, $key);

        return $key;
    }

    /**
     * @return string Registered request description
     */
    public function matchPost(string $url, ResponseInterface $response, array $requestBody, bool $registerRequestCall = true, string $key = null): string
    {
        $key ??= uuid_create() . '-POST-api-call';

        // @todo | headers
        $this->matchMethodAndUrl(Request::METHOD_POST, $url, $response, ['body' => json_encode($requestBody)], $registerRequestCall, $key);

        return $key;
    }

    private function matchMethodAndUrl(
        string $methodRegExp,
        string $urlRegexp,
        ResponseInterface $response,
        array $requestOptions,
        bool $needRegisterRequestCall,
        string $key
    ): void {
        $methodRegExp = $this->ensureRegexp($methodRegExp);
        $urlRegexp = $this->ensureRegexp($urlRegexp);

        $matcher = static function ($method, $url, $options) use ($methodRegExp, $urlRegexp, $requestOptions, $key) {
            if (!(preg_match($methodRegExp, $method) && preg_match($urlRegexp, $url))) {
                return false;
            }

            if ($expectedBody = $requestOptions['body'] ?? null) {
                return $options['body'] === $expectedBody;
            }

            return true;
        };

        $this->match($matcher, $response, $needRegisterRequestCall, $key);
    }

    /**
     * @param callable $matcher fn(string $method, string $url, array $options): bool
     */
    private function match(callable $matcher, ResponseInterface $response, bool $registerRequestCall, string $key): void
    {
        $matcher = static fn($method, $url, $options) => $matcher($method, $url, $options) ? [$response, $registerRequestCall] : null;

        $this->matchers = array_merge([$key => $matcher], $this->matchers);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function registerCall(string $key, string $method, string $url, array $options): void
    {
        $urlParts = parse_url($url);

        $params = null;
        if ($query = $urlParts['query'] ?? null) {
            $params = [];
            parse_str($query, $params);
        }

        $body = null;
        if (isset($options['body']) && is_string($options['body'])) {
            if ($json = $this->jsonDecode($options['body'])) {
                $body = $json;
            } else {
                $body = [];
                parse_str($options['body'], $body);
            }
        }

        $headers = null;
        if (isset($options['headers']) && is_array($options['headers'])) {
            $headers = [];
            foreach ($options['headers'] as $header) {
                $header = explode(': ', $header);
                $headers[$header[0]] = $header[1];
            }
        }

        $url = $urlParts['scheme'] . '://' . $urlParts['host'] . $urlParts['path'];

        $this->requestCalls[$key] = new RequestCall($method, $url, $params, $body, $headers);
    }

    private function ensureRegexp(string $regexp): string
    {
        if (preg_match("/^([^\w\s]).+\1[a-z]*$/i", $regexp) === 0) {
            foreach (['#', '@', '/', '-', ','] as $delimiter) {
                if (!str_contains($delimiter, $regexp)) {
                    break;
                }
            }

            $regexp = sprintf('%2$s^%s$%2$s', $regexp, $delimiter);
        }

        if (preg_match($regexp, '') === false) {
            throw new InvalidArgumentException(sprintf('Invalid regexp %s.', $regexp));
        }

        return $regexp;
    }

    public function getRequestCalls(): array
    {
        return $this->requestCalls;
    }

    public function getBaseUri(): ?string
    {
        return $this->baseUri;
    }
}
