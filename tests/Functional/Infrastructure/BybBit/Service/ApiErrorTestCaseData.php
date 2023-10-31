<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service;

use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Tests\Mock\Response\ByBitV5Api\ErrorResponseFactory;
use App\Tests\Utils\TestData\TestCaseDataBase;
use Throwable;

use function end;
use function explode;
use function get_class;
use function sprintf;

class ApiErrorTestCaseData extends TestCaseDataBase
{
    private function __construct(int $code, string $message, Throwable $expectedException)
    {
        $apiResponse = ErrorResponseFactory::error($code, $message);

        $exceptionClass = explode('\\', get_class($expectedException));
        $exceptionClass = end($exceptionClass);

        $data = ['apiResponse' => $apiResponse, 'expectedException' => $expectedException];

        parent::__construct($data, sprintf('API returned %d code => %s ("%s")', $code, $exceptionClass, $message));
    }

    public static function knownApiError(ApiV5Errors $error, string $message, Throwable $expectedException): self
    {
        return new self(
            $error->code(),
            $message,
            $expectedException
        );
    }

    public static function unknownApiError(string $requestUrl, int $code = 100500, string $message = 'Some error'): self
    {
        return new self(
            $code,
            $message,
            new UnknownByBitApiErrorException($code, $message, sprintf('Make `%s` request', $requestUrl))
        );
    }
}
