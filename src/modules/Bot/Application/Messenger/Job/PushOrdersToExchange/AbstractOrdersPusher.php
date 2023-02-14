<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;

abstract class AbstractOrdersPusher
{
    use LoggerTrait;

    private const SLEEP_INC = 5;
    protected int $lastSleep = 0;

    /**
     * @var PositionData[]
     */
    private array $positionsData = [];

    public function __construct(
        protected readonly PositionServiceInterface $positionService,
        protected readonly ClockInterface $clock,
        LoggerInterface $logger,
    ) {
        $this->logger = $logger;
    }

    protected  function getPositionData(Symbol $symbol, Side $side): PositionData
    {
        if (
            !($positionData = $this->positionsData[$symbol->value . $side->value] ?? null)
            || $positionData->needUpdate($this->clock->now())
        ) {
            if (!$position = $this->positionService->getOpenedPositionInfo($symbol, $side)) {
                // Fake. For run handlers. And have ability to check hedge.
                $position = new Position(
                    $side,
                    $symbol,
                    $this->positionService->getTicker($symbol)->indexPrice,
                    0,
                    0,
                    0,
                );
            }

            $this->info(
                \sprintf(
                    'UPD %s | %.3f btc (%.2f usdt) | entry: $%.2f | liq: $%.2f',
                    $position->getCaption(),
                    $position->size,
                    $position->positionValue,
                    $position->entryPrice,
                    $position->liquidationPrice,
                ));

//            if ($opposite = $this->getOppositePosition($position)) {
//                $this->info(
//                    \sprintf('Positions VALUE diff: $%.2f', abs(round($position->positionValue - $opposite->positionValue, 2)))
//                );
//            }

            $this->positionsData[$symbol->value . $side->value] = new PositionData($position, $this->clock->now());
        }

        return $this->positionsData[$symbol->value . $side->value];
    }

    protected function getOppositePosition(Position $position): ?Position
    {
        return $this->getPositionData($position->symbol, $position->side === Side::Buy ? Side::Sell : Side::Buy)->position;
    }

    protected function sleep(string $cause): void
    {
        $this->lastSleep += self::SLEEP_INC;

        $this->info(
            \sprintf(
                'Sleep for %d seconds, because %s',
                $this->lastSleep,
                $cause,
            ),
        );

        sleep($this->lastSleep);

        if ($this->lastSleep > 15) {
            $this->lastSleep = 0;
        }
    }
}
