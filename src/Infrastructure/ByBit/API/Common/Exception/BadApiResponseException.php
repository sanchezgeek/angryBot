<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\Common\Exception;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use RuntimeException;

use function gettype;
use function lcfirst;
use function sprintf;

final class BadApiResponseException extends RuntimeException
{
    private function __construct(AbstractByBitApiRequest $req, string $details, ?string $context = null)
    {
        $message = sprintf('Bad response received: %s | Check `%s %s` API contract.', lcfirst($details), $req->method(), $req->url());

        if ($context) {
            $message = sprintf('%s | %s', $context, $message);
        }

        parent::__construct($message);
    }

    public static function common(AbstractByBitApiRequest $request, string $key, ?string $context = null): self
    {
        return new self($request, sprintf('cannot find %s key in response', $key), $context);
    }

    public static function invalidItemType(AbstractByBitApiRequest $request, string $key, mixed $value, string $expectedType, ?string $context = null): self
    {
        return new self(
            $request,
            sprintf('%s must be type of type "%s" ("%s" given).', $key, $expectedType, gettype($value)),
            $context
        );
    }

    public static function cannotFindKey(AbstractByBitApiRequest $request, string $key, ?string $context = null): self
    {
        return new self($request, sprintf('cannot find %s key in response', $key), $context);
    }
}
