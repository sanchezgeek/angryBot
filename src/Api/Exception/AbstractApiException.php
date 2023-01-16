<?php

namespace App\Api\Exception;

abstract class AbstractApiException extends \Exception
{
    public function __construct(private array $errors, int $code)
    {
        if (!$errors) {
            throw new \LogicException('Errors array cannot be empty');
        }

        parent::__construct('', $code);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
