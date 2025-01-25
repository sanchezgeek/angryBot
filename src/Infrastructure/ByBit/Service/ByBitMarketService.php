<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetFundingRateHistoryRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use RuntimeException;

use function is_array;
use function sprintf;

final class ByBitMarketService implements MarketServiceInterface
{
    use ByBitApiCallHandler;

    public function __construct(
        private ClockInterface $clock,
        ByBitApiClientInterface $apiClient,
    ) {
        $this->apiClient = $apiClient;
    }

    public function getPreviousPeriodFundingRate(Symbol $symbol): float
    {
        $request = new GetFundingRateHistoryRequest($symbol->associatedCategory(), $symbol);

        $data = $this->sendRequest($request)->data();

        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $fundingRate = null;
        foreach ($list as $item) {
            if ($item['symbol'] === $symbol->value) {
                $fundingRate = (float)$item['fundingRate'];
            }
        }

        if (!$fundingRate) {
            throw new RuntimeException(sprintf('Cannot find fundingRate for "%s (%s)"', $symbol->value, $symbol->associatedCategory()->name));
        }

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
