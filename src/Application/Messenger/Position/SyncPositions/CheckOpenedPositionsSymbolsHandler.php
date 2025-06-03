<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\SyncPositions;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function json_encode;
use function sprintf;

#[AsMessageHandler]
final readonly class CheckOpenedPositionsSymbolsHandler
{
    public function __invoke(CheckOpenedPositionsSymbolsMessage $message): void
    {
        $openedPositionsSymbols = $this->positionService->getOpenedPositionsRawSymbols();

        foreach ($openedPositionsSymbols as $symbolRaw) {
            if (!SymbolEnum::tryFrom($symbolRaw)) {
                $instrumentInfo = $this->exchangeService->getInstrumentInfo($symbolRaw);
                $this->appErrorLogger->critical(
                    sprintf('Found position on "%s", which haven\'t definition in "Symbol" enum. Instrument info: %s', $symbolRaw, json_encode($instrumentInfo)),
                );
            }
        }


        // @todo | check minimum value and qnt: https://announcements.bybit.com/article/update-on-minimum-single-order-value-and-quantity-for-spot-and-margin-trading-pairs-blt96f1cea87db75db9/
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
