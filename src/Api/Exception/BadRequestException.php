<?php

declare(strict_types=1);

namespace App\Api\Exception;

use Symfony\Component\HttpFoundation\Response;

final class BadRequestException extends AbstractApiException
{
    private function __construct(array $errors)
    {
        parent::__construct($errors, Response::HTTP_BAD_REQUEST);
    }

    public static function errors(array $errors): self
    {
        return new self($errors);
    }

    public static function error(string $message, ?string $field = null): self
    {
        $error = \array_merge(
            $field ? ['field' => $field] : [],
            ['message' => $message],
        );

        return self::errors([$error]);
    }
}
