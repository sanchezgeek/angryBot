<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Api\Exception\AbstractApiException;
use App\Api\Response\ErrorResponseDto;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

#[AsEventListener]
final class ApiErrorExceptionListener
{
    private const API_EXCEPTIONS = [];

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!(
            $exception instanceof AbstractApiException
            || \in_array(\get_class($exception), self::API_EXCEPTIONS)
        )) {
            return;
        }

        $errors = $exception instanceof AbstractApiException
            ? $exception->getErrors()
            : [['message' => $exception->getMessage()]]
        ;

        $code = $exception instanceof AbstractApiException
            ? $exception->getCode()
            : Response::HTTP_BAD_REQUEST
        ;

        $event->setResponse(
            new JsonResponse(new ErrorResponseDto($errors), $code),
        );
    }
}
