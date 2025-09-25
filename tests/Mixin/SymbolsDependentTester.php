<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Tests\Fixture\SymbolFixture;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use App\Trading\Domain\Symbol\SymbolContainerInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

trait SymbolsDependentTester
{
    use TestWithDoctrineRepository;
    use TestWithDbFixtures;

    const array SYMBOLS = [
        'BTCUSD',
        'BTCUSDT',
        'ETHUSDT',
        'LINKUSDT',
        'AAVEUSDT',
        'ADAUSDT',
        'XRPUSDT',
        'SOLUSDT',
        'ADAUSDT',
        'TONUSDT',
        'VIRTUALUSDT',
        'BNBUSDT',
        'GRIFFAINUSDT',
    ];

    static bool $symbolsInitialized = false;
    private static array $symbolsCache = [];

    /**
     * @before
     */
    protected function loadSymbols(): void
    {
        if (!self::$symbolsInitialized) {
            /** @var EntityManagerInterface $entityManager */
            $entityManager = self::getContainer()->get(EntityManagerInterface::class);

            foreach (self::SYMBOLS as $symbol) {
                if (!$entityManager->find(Symbol::class, $symbol)) {
                    $this->applyDbFixtures(new SymbolFixture(self::makeSymbolEntityFromKnownEnumByName($symbol)));
                }
            }

            self::$symbolsInitialized = true;
        }
    }

    private static function makeSymbolEntityFromKnownEnumByName(string $symbolName): Symbol
    {
        if (!$symbolEnum = SymbolEnum::from($symbolName)) {
            throw new RuntimeException(sprintf('Cannot find SymbolEnum by "%s" when to try create symbol entity', $symbolName));
        }

        return new Symbol(
            $symbolName,
            $symbolEnum->associatedCoin(),
            $symbolEnum->associatedCategory(),
            $symbolEnum->minOrderQty(),
            $symbolEnum->minNotionalOrderValue(),
            $symbolEnum->pricePrecision(),
        );
    }

    private static function getSymbolEntity(string $name): ?Symbol
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (
            !isset(self::$symbolsCache[$name])
            || !$entityManager->getUnitOfWork()->isInIdentityMap(self::$symbolsCache[$name])
        ) {
            self::$symbolsCache[$name] = self::getContainer()->get(SymbolRepository::class)->findOneBy(['name' => $name]);
        }

        return self::$symbolsCache[$name];
    }

    private static function replaceEnumSymbol(SymbolContainerInterface $order): void
    {
        $order->replaceSymbolEntity(self::getSymbolEntity($order->getSymbol()->name()));
    }
}
