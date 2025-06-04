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
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class UseNegativeCachedResultWhileCheckDecorator implements TradingCheckInterface
{
    private const DEFAULT_CACHE_TTL = 45;
    private const MAX_ITEMS = 100;

    private static array $pnlStepCache = [];

    private readonly CacheServiceInterface $cache;

    public function __construct(private readonly TradingCheckInterface $decorated)
    {
        $this->cache = new SymfonyCacheWrapper(new ArrayAdapter(self::DEFAULT_CACHE_TTL, true, 0, self::MAX_ITEMS));
    }

    public function alias(): string
    {
        return $this->decorated->alias();
    }


    public function supports(CheckOrderDto $orderDto, TradingCheckContext $context): bool
    {
        // cache?
        return $this->decorated->supports($orderDto, $context);
    }

    public function check(CheckOrderDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        // @todo use position state?
        $symbol = $orderDto->symbol();
        $cacheKey = (new CheckResultKeyBasedOnOrderAndPricePnlStep(
            $orderDto->priceValueWillBeingUsedAtExecution(),
            $orderDto->orderQty(),
            $symbol,
            $orderDto->positionSide(),
            self::pnlPercentStep($symbol, $orderDto->priceValueWillBeingUsedAtExecution())
        ))->generate();

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

    /**
     * @note 100x-leveraged
     * @see PnlHelper::getPositionLeverage
     */
    public static function pnlPercentStep(SymbolInterface $symbol, float $currentPrice): int
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
