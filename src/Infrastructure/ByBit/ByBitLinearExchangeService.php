<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit;

use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
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
     */
    public function ticker(Symbol $symbol): Ticker
    {
        $result = $this->apiClient->send(
            $request = new GetTickersRequest(self::ASSET_CATEGORY, $symbol)
        );

        if (!$result->isSuccess()) {
            $error = $result->error();
            if ($error instanceof ApiV5Error) {
                throw match ($error) {
                    ApiV5Error::ApiRateLimitReached => new ApiRateLimitReached(),
                    default => new RuntimeException(
                        sprintf('%s | make `%s`: unknown err code (%d)', __METHOD__, $request->url(), $error->code())
                    )
                };
            }

            throw new RuntimeException(
                sprintf(
                    '%s | make `%s`: got errCode %d (%s)',
                    __METHOD__,
                    $request->url(),
                    $result->error()->code(),
                    $result->error()->desc(),
                )
            );
        }

        $data = $result->data();

        $ticker = null;
        foreach ($data['list'] as $item) {
            if ($item['symbol'] === $symbol->value) {
                $updatedBy = $this->workerHash ?? AppContext::workerHash();
                $ticker = new Ticker($symbol, (float)$item['markPrice'], (float)$item['indexPrice'], $updatedBy);
            }
        }

        \assert($ticker !== null, 'Ticker not found');

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
}
