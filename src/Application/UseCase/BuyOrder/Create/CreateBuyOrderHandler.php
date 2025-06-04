<?php

declare(strict_types=1);

namespace App\Application\UseCase\BuyOrder\Create;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Trading\Application\Symbol\SymbolProvider;

final readonly class CreateBuyOrderHandler
{
    public function __construct(
        private BuyOrderRepository $repository,
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function handle(CreateBuyOrderEntryDto $dto): CreateBuyOrderResultDto
    {
        // @todo | buy | round here?
//        $exchangeOrder = ExchangeOrder::roundedToMin($symbol, $volume, $orderPrice); ?

        $id = $this->repository->getNextId();

        $buyOrder = new BuyOrder(
            $id,
            $dto->price,
            $dto->volume,
            $this->symbolProvider->replaceEnumWithEntity($dto->symbol),
            $dto->side,
            $dto->context
        );

        $this->repository->save($buyOrder);

        return new CreateBuyOrderResultDto($buyOrder);
    }
}
