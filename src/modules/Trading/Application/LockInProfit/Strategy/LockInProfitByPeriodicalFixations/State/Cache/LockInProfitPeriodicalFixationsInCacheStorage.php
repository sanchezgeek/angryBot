<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\State\Cache;

use App\Application\Cache\AbstractCacheService;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\State\LockInProfitPeriodicalFixationsStorageInterface;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\State\PeriodicalFixationStepState;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\Step\PeriodicalFixationStep;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

final class LockInProfitPeriodicalFixationsInCacheStorage extends AbstractCacheService implements LockInProfitPeriodicalFixationsStorageInterface
{
    private static function allStoredKeysCacheKey(): string
    {
        return 'LockInProfitPeriodicalFixationsCache_all-stored-keys';
    }

    private static function stateCacheKey(SymbolInterface $symbol, Side $positionSide, PeriodicalFixationStep $step): string
    {
        return sprintf('LockInProfitPeriodicalFixationsCache_%s_%s_%s', $symbol->name(), $positionSide->value, $step->alias);
    }

    /**
     * @return string[]
     */
    public function getAllStoredKeys(): array
    {
        $key = self::allStoredKeysCacheKey();

        return $this->cache->get($key) ?? [];
    }

    public function getStateByStoredKey(string $key): PeriodicalFixationStepState
    {
        $state = $this->get($key);

        if ($state instanceof PeriodicalFixationStepState) {
            throw new RuntimeException(sprintf('Cannot get state by key "%s"', $key));
        }

        return $state;
    }

    public function getState(SymbolInterface $symbol, Side $positionSide, PeriodicalFixationStep $step): ?PeriodicalFixationStepState
    {
        return $this->get(self::stateCacheKey($symbol, $positionSide, $step));
    }

    public function saveState(PeriodicalFixationStepState $state): void
    {
        $stateCacheKey = self::stateCacheKey($state->symbol, $state->positionSide, $state->step);

        $this->save($stateCacheKey, $state);

        $this->addKeyToStorage($stateCacheKey);
    }

    public function removeState(PeriodicalFixationStepState $state): void
    {
        $this->remove(
            self::stateCacheKey($state->symbol, $state->positionSide, $state->step)
        );
    }

    public function removeStateBySymbolAndSide(SymbolInterface $symbol, Side $side): void
    {
        foreach ($this->getAllStoredKeys() as $stateCacheKey) {
            if (str_contains($stateCacheKey, sprintf('%s_%s', $symbol->name(), $side->value))) {
                $this->remove($stateCacheKey);
            }
        }
    }

    private function addKeyToStorage(string $key): void
    {
        $allStoredKeysCacheKey = self::allStoredKeysCacheKey();

        $allStoredKeys = $this->get($allStoredKeysCacheKey);
        if (!in_array($key, $allStoredKeys, true)) {
            $allStoredKeys[] = $key;
        }

        $this->save($allStoredKeysCacheKey, $allStoredKeys);
    }
}
