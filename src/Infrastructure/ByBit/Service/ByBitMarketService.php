<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetFundingRateHistoryRequest;
use App\Infrastructure\ByBit\Cache\MarketDataCache;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;
use RuntimeException;

use function is_array;
use function sprintf;

final class ByBitMarketService implements MarketServiceInterface
{
    use ByBitApiCallHandler;

    public function __construct(
        private ClockInterface $clock,
        ByBitApiClientInterface $apiClient,
        private readonly MarketDataCache $cache,
    ) {
        $this->apiClient = $apiClient;
    }

    public function getPreviousPeriodFundingRate(SymbolInterface $symbol, int $limit = 1): float
    {
        $last = $this->cache->getLastFundingRate($symbol);

        if ($last !== null) {
            return $last;
        }

        $request = new GetFundingRateHistoryRequest($symbol->associatedCategory(), $symbol, $limit);

        $data = $this->sendRequest($request)->data();

        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $fundingRate = null;
        foreach ($list as $item) {
            if ($item['symbol'] === $symbol->name()) {
                $fundingRate = (float)$item['fundingRate'];
            }
        }

        if ($fundingRate === null) {
            throw new RuntimeException(sprintf('Cannot find fundingRate for "%s (%s)"', $symbol->name(), $symbol->associatedCategory()->name));
        }

        $this->cache->setLastFundingRate($symbol, $fundingRate);

        return $fundingRate;
    }

    public function isNowFundingFeesPaymentTime(): bool
    {
        $fundingFeesPaymentIntervals = [['235945', '240100'], ['075945', '080100'], ['155945', '160100']];

        $now = $this->clock->now()->format('His');
        if ($now < '010000') {
            $now += 240000;
        }

        foreach ($fundingFeesPaymentIntervals as [$from, $to]) {
            if ($now >= $from && $now <= $to) {
                return true;
            }
        }

        return false;
    }
}
