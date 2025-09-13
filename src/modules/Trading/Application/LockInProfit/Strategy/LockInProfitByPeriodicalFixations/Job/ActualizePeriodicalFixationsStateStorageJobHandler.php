<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\Job;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\State\LockInProfitPeriodicalFixationsStorageInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ActualizePeriodicalFixationsStateStorageJobHandler
{
    public function __construct(
        private LockInProfitPeriodicalFixationsStorageInterface $storage,
        private ByBitLinearPositionService $positionService,
    ) {
    }

    public function __invoke(ActualizePeriodicalFixationsStateStorageJob $job): void
    {
        $allStoredStates = [];
        foreach ($this->storage->getAllStoredKeys() as $key) {
            $allStoredStates[] = $this->storage->getStateByStoredKey($key);
        }

        $openedPositionsKeys = [];
        $openedPositions = $this->positionService->getAllPositions();
        foreach ($openedPositions as $symbolPositions) {
            $symbolPositionsKeys = array_map(static fn (Position $position) => self::getPositionKey($position->symbol, $position->side), $symbolPositions);
            $openedPositionsKeys = array_merge($openedPositionsKeys, $symbolPositionsKeys);
        }

        foreach ($allStoredStates as $state) {
            $key = self::getPositionKey($state->symbol, $state->positionSide);
            if (!in_array($key, $openedPositionsKeys, true)) {
                OutputHelper::print(sprintf('Remove fixations state for %s', $key));
                $this->storage->removeState($state);
            }
        }
    }

    private static function getPositionKey(SymbolInterface $symbol, Side $positionSide): string
    {
        return sprintf('%s_%s', $symbol->name(), $positionSide->value);
    }
}
