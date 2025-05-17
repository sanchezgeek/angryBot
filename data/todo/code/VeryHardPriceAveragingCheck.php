<?php

declare(strict_types=1);

namespace data\todo\code;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use data\code\MarketBuyCheckInterface;

/**
 * @see \App\Tests\Unit\Application\UseCase\Trading\MarketBuy\Checks\VeryHardPriceAveragingCheckTest
 */
final class VeryHardPriceAveragingCheck implements MarketBuyCheckInterface
{
    private const DISTANCE_PNL_PCT = 250;

    private Position $openedPosition;

    public function __construct(Position $openedPosition)
    {
        $this->openedPosition = $openedPosition;
    }

    /**
     * @throws OrderExecutionLeadsToVeryHardPriceAveragingException
     */
    public function check(MarketBuyEntryDto $order, Ticker $ticker): void
    {
        $position = $this->openedPosition;
        $distance = $ticker->lastPrice->differenceWith($position->entryPrice());

        if (!$distance->isProfitFor($position->side)) {
            return;
        }

        if ($distance->absPercentDelta($position->entryPrice)->value() > self::DISTANCE_PNL_PCT) {
            throw new OrderExecutionLeadsToVeryHardPriceAveragingException();
        }
    }
}
