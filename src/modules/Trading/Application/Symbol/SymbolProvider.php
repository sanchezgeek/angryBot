<?php

declare(strict_types=1);

namespace App\Trading\Application\Symbol;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Domain\Coin\Coin;
use App\Helper\OutputHelper;
use App\Trading\Application\Symbol\Exception\SymbolEntityNotFoundException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\QuoteCoinNotEqualsSpecifiedOneException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\UnsupportedAssetCategoryException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolsEntry;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolsHandler;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use App\Trading\Domain\Symbol\SymbolInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class SymbolProvider
{
    public function __construct(
        private SymbolRepository $symbolRepository,
        private InitializeSymbolsHandler $initializeSymbolsHandler, /** @todo | symbol | messageBus */
        private EntityManagerInterface $entityManager,
        private AppErrorLoggerInterface $appErrorLogger,
        private RateLimiterFactory $symbolInitializeExceptionThrottlingLimiter
    ) {
    }

    /**
     * @throws SymbolEntityNotFoundException
     */
    public function getOneByName(string $name): Symbol
    {
        if ($symbol = $this->symbolRepository->findOneByName($name)) {
            return $symbol;
        }

        throw new SymbolEntityNotFoundException(sprintf('Cannot find symbol by "%s" name', $name));
    }

    /**
     * Can be safely used when there is no stored entity yet
     *
     * @throws UnsupportedAssetCategoryException
     *
     * @todo | symbol | TRY TO OPEN SOME UNSUPPORTED POSITON ON TESTNET (UnsupportedAssetCategoryException, QuoteCoinNotEqualsSpecifiedOneException)
     */
    public function getOrInitialize(string $name, bool $logException = true): Symbol
    {
        try {
            return $this->doGetOrInitialize($name);
        } catch (QuoteCoinNotEqualsSpecifiedOneException|UnsupportedAssetCategoryException $e) {
            $logException && $this->logInitializeSymbolException($e, $name);
            throw $e;
        }
    }

    /**
     * Can be safely used when there is no stored entity yet
     *
     * @throws UnsupportedAssetCategoryException
     * @throws QuoteCoinNotEqualsSpecifiedOneException
     */
    public function getOrInitializeWithCoinSpecified(string $name, ?Coin $coin = null, bool $logException = false): Symbol
    {
        try {
            return $this->doGetOrInitialize($name, $coin);
        } catch (QuoteCoinNotEqualsSpecifiedOneException|UnsupportedAssetCategoryException $e) {
            $logException && $this->logInitializeSymbolException($e, $name);
            throw $e;
        }
    }

    /**
     * @throws UnsupportedAssetCategoryException
     * @throws QuoteCoinNotEqualsSpecifiedOneException
     */
    private function doGetOrInitialize(string $name, ?Coin $coin = null): Symbol
    {
        try {
            return $this->getOneByName($name);
        } catch (SymbolEntityNotFoundException $e) {
            try {
                return $this->initializeSymbolsHandler->handle(
                    new InitializeSymbolsEntry($name, $coin)
                );
            } catch (UniqueConstraintViolationException $e) {
                return $this->getOneByName($name);
            }
        }
    }

    /**
     * Use when need populate entities associations with Symbol
     */
    public function replaceWithActualEntity(SymbolInterface $symbol): SymbolInterface
    {
        return
            !$symbol instanceof Symbol || !$this->entityManager->getUnitOfWork()->isInIdentityMap($symbol)
                ? $this->getOrInitialize($symbol->name())
                : $symbol
            ;
    }

    private function logInitializeSymbolException(
        QuoteCoinNotEqualsSpecifiedOneException|UnsupportedAssetCategoryException $exception,
        string $symbolName,
    ): void {
        if ($this->symbolInitializeExceptionThrottlingLimiter->create($symbolName)->consume()->isAccepted()) {
            $this->appErrorLogger->exception(
                $exception,
                sprintf('[%s] Get error while try to process "%s"', OutputHelper::shortClassName(__CLASS__), $symbolName)
            );
        }
    }
}
