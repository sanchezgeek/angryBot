<?php

declare(strict_types=1);

namespace App\Trading\Infrastructure\Cache;

use App\Application\Cache\AbstractCacheService;
use App\Application\Cache\CacheServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Domain\Coin\Coin;
use App\Trading\Contract\ContractBalanceProviderInterface;

final class CachedContractBalanceProvider extends AbstractCacheService implements ContractBalanceProviderInterface
{
    private const int TTL = 4;

    public function __construct(
        CacheServiceInterface $cache,
        private readonly ContractBalanceProviderInterface $innerProvider,
    ) {
        parent::__construct($cache);
    }

    public function getContractWalletBalance(Coin $coin): ContractBalance
    {
        return $this->cache->get(
            sprintf('tradingAccount_contractBalance_%s', $coin->value),
            fn() => $this->innerProvider->getContractWalletBalance($coin),
            self::TTL
        );
    }
}
