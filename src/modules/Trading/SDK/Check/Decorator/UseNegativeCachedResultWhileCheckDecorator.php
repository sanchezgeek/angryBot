<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Decorator;

use App\Application\Cache\CacheServiceInterface;
use App\Domain\Stop\Helper\PnlHelper;
use App\Infrastructure\Cache\SymfonyCacheWrapper;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trading\SDK\Check\Cache\CheckResultKeyBasedOnOrderAndPricePnlStep;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use Closure;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class UseNegativeCachedResultWhileCheckDecorator implements TradingCheckInterface
{
    private const int DEFAULT_CACHE_TTL = 45;
    private const int MAX_ITEMS = 100;

    private static array $pnlStepCache = [];

    private readonly CacheServiceInterface $cache;

    /**
     * @param Closure|null $cacheKeyFactory Function that get CheckOrderDto and TradingCheckContext as parameters
     */
    public function __construct(
        private readonly TradingCheckInterface $decorated,
        int $ttl = self::DEFAULT_CACHE_TTL,
        private readonly ?Closure $cacheKeyFactory = null,
    ) {
        $this->cache = new SymfonyCacheWrapper(new ArrayAdapter($ttl, true, 0, self::MAX_ITEMS));
    }

    public function alias(): string
    {
        return $this->decorated->alias();
    }

    public function supports(CheckOrderDto $orderDto, TradingCheckContext $context): bool
    {
        // @todo | checks | cache?
        return $this->decorated->supports($orderDto, $context);
    }

    public function check(CheckOrderDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $cacheKey = $this->getCacheKey($orderDto, $context);

        /** @var AbstractTradingCheckResult $cachedResult */
        if ($cachedResult = $this->cache->get($cacheKey)) {
//            OutputHelper::warning(sprintf('hit %s: %s (%s %s %s)', $cacheKey, $orderDto->orderIdentifier(), $context->ticker->symbol->name(), $context->ticker->markPrice->value(), $orderDto->orderQty()));
            return $cachedResult->quietClone();
        }

        $actualResult = $this->decorated->check($orderDto, $context);

        if (!$actualResult->success) {
            $this->cache->save($cacheKey, $actualResult);
        }

        return $actualResult;
    }

    private function getCacheKey(CheckOrderDto $orderDto, TradingCheckContext $context): string
    {
        $keyFactory = $this->cacheKeyFactory ?? static fn (CheckOrderDto $orderDto) => new CheckResultKeyBasedOnOrderAndPricePnlStep(
            $orderDto->priceValueWillBeingUsedAtExecution(),
            $orderDto->orderQty(),
            $orderDto->symbol(),
            $orderDto->positionSide(),
            self::pnlPercentStep($orderDto->symbol(), $orderDto->priceValueWillBeingUsedAtExecution())
        )->generate();

        return $keyFactory($orderDto, $context);
    }

    /**
     * @note 100x-leveraged
     * @see PnlHelper::getPositionLeverage
     */
    private static function pnlPercentStep(SymbolInterface $symbol, float $currentPrice): int
    {
        if (isset(self::$pnlStepCache[$symbol->name()])) {
            return self::$pnlStepCache[$symbol->name()];
        }

        $result = match (true) {
            $currentPrice <= 0.01 => 100,
            $currentPrice <= 0.03 => 60,
            $currentPrice <= 0.06 => 40,
            $currentPrice <= 1 => 30,
            $currentPrice <= 2 => 25,
            default => 20,
        };

        return self::$pnlStepCache[$symbol->name()] = $result;
    }
}
