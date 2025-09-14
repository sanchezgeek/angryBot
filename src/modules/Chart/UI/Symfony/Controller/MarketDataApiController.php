<?php

declare(strict_types=1);

namespace App\Chart\UI\Symfony\Controller;

use App\Infrastructure\ByBit\Service\Market\ByBitLinearMarketService;
use App\Trading\Application\Symbol\SymbolProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketDataApiController extends AbstractController
{
    public function __construct(
        private readonly SymbolProvider $symbolProvider,
        private readonly ByBitLinearMarketService $marketService,
    ) {
    }

    #[Route(path: '/instrument-info/{symbol}', requirements: ['symbol' => '\w+'])]
    public function instrumentInfo(string $symbol): Response
    {
        $symbol = $this->symbolProvider->getOrInitialize($symbol);
        $info = $this->marketService->getInstrumentInfo($symbol);

        return new JsonResponse(['info' => $info]);
    }
}
