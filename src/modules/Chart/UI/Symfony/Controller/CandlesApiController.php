<?php

declare(strict_types=1);

namespace App\Chart\UI\Symfony\Controller;

use App\Chart\Application\CandlesProvider;
use App\Trading\Application\Symbol\SymbolProvider;
use DateInterval;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CandlesApiController extends AbstractController
{
    public function __construct(
        private readonly CandlesProvider $candlesProvider,
        private readonly SymbolProvider $symbolProvider,
    ) {
    }

    #[Route(path: '/candles/list/{symbol}', requirements: ['symbol' => '\w+'])]
    public function openedPositions(string $symbol): Response
    {
        $symbolParsed = $this->symbolProvider->getOrInitialize($symbol);

        $to = date_create_immutable();
        $from = $to->sub(new DateInterval('P1D'));

        return new JsonResponse(
            $this->candlesProvider->getCandles($symbolParsed, $from, $to)
        );
    }
}
