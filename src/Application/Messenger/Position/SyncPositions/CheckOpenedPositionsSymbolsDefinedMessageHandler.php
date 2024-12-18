<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\SyncPositions;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

#[AsMessageHandler]
final readonly class CheckOpenedPositionsSymbolsDefinedMessageHandler
{
    public function __invoke(CheckOpenedPositionsSymbolsDefinedMessage $message): void
    {
        $openedPositionsSymbols = $this->positionService->getOpenedPositionsRawSymbols();

        foreach ($openedPositionsSymbols as $symbolRaw) {
            if (!Symbol::tryFrom($symbolRaw)) {
                $instrumentInfo = $this->exchangeService->getInstrumentInfo($symbolRaw);
                $this->appErrorLogger->critical(
                    sprintf('Found position on "%s", which haven\'t definition in "Symbol" enum. Instrument info: %s', $symbolRaw, json_encode($instrumentInfo)),
                );
            }
        }
    }

    /**
     * @param ByBitLinearExchangeCacheDecoratedService $exchangeService
     */
    public function __construct(
        private LoggerInterface $appErrorLogger,
        private PositionServiceInterface $positionService,
        private ExchangeServiceInterface $exchangeService,
    ) {
    }
}
