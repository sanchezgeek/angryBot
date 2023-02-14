<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Service\Stop\StopService;
use App\Clock\ClockInterface;
use App\Helper\VolumeHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IncreaseHedgeSupportPositionHandler extends AbstractOrdersPusher
{
    private const MIN_VOLUME = 0.001;
    private const DEFAULT_TRIGGER_DELTA = 3;

    private const PRICE_STEP = 3; // To not allow to stop too much position size

    /**
     * @var array<string, float>
     */
    private array $lastAddedOnPrice = [];

    public function __construct(
        private readonly StopService $stopService,

        PositionServiceInterface $positionService,
        LoggerInterface $logger,
        ClockInterface $clock,
    ) {
        parent::__construct($positionService, $clock, $logger);
    }

    // @todo Ещё можно попробовать фиксировать прибыль на supportPosition, чтобы ей было на что докупаться
    // Но это имеет смысл, если там есть профит больше 50%
    public function __invoke(IncreaseHedgeSupportPositionByGetProfitFromMain $command): void
    {
        var_dump(
            \sprintf('%s received.', IncreaseHedgeSupportPositionByGetProfitFromMain::class)
        );

        $supportedPosition = $this->getPositionData($command->symbol, $command->side);
        $mainPosition = $this->getOppositePosition($supportedPosition->position);

        if (!$mainPosition || !$supportedPosition->position) {
            return;
        }

        $hedge = Hedge::create($supportedPosition->position, $mainPosition);

        if (!$hedge->isSupportPosition($supportedPosition->position)) {
            return;
        }

        if (!$hedge->needIncreaseSupport()) {
//            $this->info(
//                \sprintf('Support is already filled. Current rate: %.3f', $hedge->getSupportRate())
//            );

            return;
        }

        $ticker = $this->positionService->getTicker($command->symbol);

        // If mainPosition now in loss
        if ($ticker->isIndexPriceAlreadyOverStopPrice($mainPosition->side, $mainPosition->entryPrice)) {
            return;
        }

        $triggerPrice = $mainPosition->side === Side::Sell ? $ticker->indexPrice - 10 : $ticker->indexPrice + 10;

        if (!$this->canAddStopOnPrice($mainPosition->side, $triggerPrice)) {
            return;
        }

        $volume = VolumeHelper::round($command->qty / 13);

        $this->stopService->create(
            $mainPosition->side,
            $triggerPrice,
            $volume,
            self::DEFAULT_TRIGGER_DELTA,
            ['asSupportFromMainHedgePosition' => true, 'createdWhen' => 'catchException'],
        );

        $this->lastAddedOnPrice[$mainPosition->side->value] = $triggerPrice;
    }

    public function canAddStopOnPrice(Side $side, float $triggerPrice): bool
    {
        $lastAddedOnPrice = $this->lastAddedOnPrice[$side->value] ?? null;

        return
            $lastAddedOnPrice === null
            || ($side === Side::Sell && $triggerPrice > ($lastAddedOnPrice + self::PRICE_STEP))
            || ($side === Side::Buy && $triggerPrice < ($lastAddedOnPrice - self::PRICE_STEP))
        ;
    }
}
