<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\SyncPositions;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use App\Worker\AppContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function in_array;
use function json_encode;
use function sprintf;

#[AsMessageHandler]
final readonly class CheckOpenedPositionsSymbolsHandler
{
    public function __invoke(CheckOpenedPositionsSymbolsMessage $message): void
    {
        $openedPositionsSymbols = $this->positionService->getOpenedPositionsRawSymbols();
        $openedPositionsSymbolsFromAppContext = AppContext::getOpenedPositions();

        foreach ($openedPositionsSymbols as $symbolRaw) {
            if (!$symbol = Symbol::tryFrom($symbolRaw)) {
                $instrumentInfo = $this->exchangeService->getInstrumentInfo($symbolRaw);
                $this->appErrorLogger->critical(
                    sprintf('Found position on "%s", which haven\'t definition in "Symbol" enum. Instrument info: %s', $symbolRaw, json_encode($instrumentInfo)),
                );
                continue;
            }

            if ($symbol !== Symbol::BTCUSDT && !in_array($symbol, $openedPositionsSymbolsFromAppContext)) {
                $this->appErrorLogger->critical(
                    sprintf('Found position on "%s", which isn\'t defined in AppContext::getOpenedPositions()', $symbol->value),
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
