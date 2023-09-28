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
use function explode;
use function http_build_query;
use function is_array;
use function is_string;
use function parse_str;
use function preg_match;
use function str_contains;

/**
 * @see \App\Tests\Unit\Stub\SymfonyHttpClientStubTest
 */
class SymfonyHttpClientStub extends MockHttpClient
{
    use JsonTrait;

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

        $this->defaultResponse = new MockResponse('', ['http_code' => 404]);
    }

    private function handler(): Closure
    {
        return function (string $method, string $url, array $options) {
            foreach ($this->matchers as $matcher) {
                if ($response = $matcher($method, $url, $options)) {
                    break;
                }
            }

            $this->registerCall($method, $url, $options);

            return $response ?? $this->defaultResponse;
        };
    }

    /**
     * @param array<string, string> $params
     */
    public function matchGet(string $url, array $params, ResponseInterface $response): self
    {
        $url = $params ? $url . '?' . http_build_query($params) : $url;
        $urlRegexp = addcslashes($url, '?+.*');

        return $this->matchMethodAndUrl(Request::METHOD_GET, $urlRegexp, $response);
    }

    public function matchPost(string $url, ResponseInterface $response): self
    {
        return $this->matchMethodAndUrl(Request::METHOD_POST, $url, $response);
    }

    public function matchMethodAndUrl(string $methodRegExp, string $urlRegexp, ResponseInterface $response): self
    {
        $methodRegExp = $this->ensureRegexp($methodRegExp);
        $urlRegexp = $this->ensureRegexp($urlRegexp);

        return $this->match(
            static fn ($method, $url) => preg_match($methodRegExp, $method) && preg_match($urlRegexp, $url),
            $response,
        );
    }

    /**
     * @param callable $matcher fn(string $method, string $url, array $options): bool
     */
    public function match(callable $matcher, ResponseInterface $result): self
    {
        $this->matchers[] = static fn ($method, $url, $options) => $matcher($method, $url, $options) ? $result : null;

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function registerCall(string $method, string $url, array $options): void
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

        $this->requestCalls[] = new RequestCall($method, $url, $params, $body, $headers);
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
}
