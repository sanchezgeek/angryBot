<?php

declare(strict_types=1);

namespace App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CoverLossesAfterCloseByMarketConsumer
{
    public function __invoke(CoverLossesAfterCloseByMarketConsumerDto $dto): void
    {
        $loss = $dto->loss;
        $closedPosition = $dto->closedPosition;

        // @todo | check `contractBalance.total` >= `positions.totalIM` instead? To not cover existed losses from SPOT | + if some setting is set?
        if ($closedPosition->isSupportPosition()) {
            return;
        }

        $this->exchangeAccountService->interTransferFromSpotToContract($closedPosition->symbol->associatedCoin(), $loss->value());
    }

    public function __construct(
        private ExchangeAccountServiceInterface $exchangeAccountService
    ) {
    }
}