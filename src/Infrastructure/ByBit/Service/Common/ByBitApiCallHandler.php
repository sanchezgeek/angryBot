<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Common;

use App\Infrastructure\ByBit\API\Common\ByBitApiCallResult;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use Closure;

use function debug_backtrace;
use function end;
use function explode;
use function get_class;
use function sprintf;

trait ByBitApiCallHandler
{
    private ByBitApiClientInterface $apiClient;

    /**
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     * @throws ApiRateLimitReached
     */
    private function sendRequest(
        AbstractByBitApiRequest $request,
        ?Closure $knownApiErrorsResolver = null,
        ?string $calledServiceMethod = null,
    ): ByBitApiCallResult {
        $result = $this->apiClient->send($request);

        if (!$result->isSuccess()) {
            $error = $result->error();

            if ($knownApiErrorsResolver) {
                $knownApiErrorsResolver($error);
            }

            throw new UnexpectedApiErrorException(
                $error->code(),
                $error->msg(),
                $this->context($request, $calledServiceMethod ?: debug_backtrace()[1]['function'])
            );
        }

        return $result;
    }

    private function context(AbstractByBitApiRequest $request, string $inMethod): string
    {
        $class = explode('\\', get_class($this));
        $service = end($class);
        return sprintf('%s::%s | make `%s`', $service, $inMethod, $request->url());
    }
}
