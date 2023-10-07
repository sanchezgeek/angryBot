<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit;

use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Infrastructure\ByBit\Exception\ByBitTickerNotFoundException;
use App\Worker\AppContext;
use RuntimeException;
use Symfony\Polyfill\Intl\Icu\Exception\NotImplementedException;

use function sprintf;

/**
 * @todo | now only for `linear` AssetCategory
 */
final readonly class ByBitLinearExchangeService
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    public function __construct(private ByBitApiClientInterface $apiClient, private ?string $workerHash)
    {
    }

    /**
     * @throws ApiRateLimitReached|RuntimeException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeService\GetTickerTest
     */
    public function ticker(Symbol $symbol): Ticker
    {
        $result = $this->apiClient->send(
            $request = new GetTickersRequest(self::ASSET_CATEGORY, $symbol)
        );

        if (!$result->isSuccess()) {
            match ($error = $result->error()) {
                ApiV5Error::ApiRateLimitReached => throw new ApiRateLimitReached(),
                default => $this->processUnknownApiError($request, $error, __METHOD__),
            };
        }

        $data = $result->data();

        $ticker = null;
        foreach ($data['list'] as $item) {
            if ($item['symbol'] === $symbol->value) {
                $updatedBy = $this->workerHash ?? AppContext::workerHash();
                $ticker = new Ticker($symbol, (float)$item['markPrice'], (float)$item['indexPrice'], $updatedBy);
            }
        }

        \assert($ticker !== null, ByBitTickerNotFoundException::forSymbolAndCategory($symbol, self::ASSET_CATEGORY));

        return $ticker;
    }

    public function activeConditionalOrders(Symbol $symbol): array
    {
        throw new NotImplementedException('must be implemented later');
    }

    public function closeActiveConditionalOrder(ActiveStopOrder $order)
    {
        throw new NotImplementedException('must be implemented later');
    }

    /**
     * @todo | apiV5 | trait
     */
    private function processUnknownApiError(AbstractByBitApiRequest $request, ApiErrorInterface $err, string $in): void
    {
        throw new RuntimeException(
            sprintf('%s | make `%s`: unknown errCode %d (%s)', $in, $request->url(), $err->code(), $err->desc())
        );
    }
}
