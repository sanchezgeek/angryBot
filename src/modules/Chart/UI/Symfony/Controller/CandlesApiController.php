<?php

declare(strict_types=1);

namespace App\Chart\UI\Symfony\Controller;

use App\Domain\Trading\Enum\TimeFrame;
use App\TechnicalAnalysis\Application\Service\Candles\PreviousCandlesProvider;
use App\Trading\Application\Symbol\SymbolProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CandlesApiController extends AbstractController
{
    public function __construct(
        private readonly SymbolProvider $symbolProvider,
        private readonly PreviousCandlesProvider $candlesProvider,
    ) {
    }

    #[Route(path: '/candles/list/{symbol}/{timeFrame}', requirements: ['symbol' => '\w+', 'timeFrame' => '\w+'])]
    public function symbols(string $symbol, string $timeFrame): Response
    {
        $timeFrame = TimeFrame::from($timeFrame);
        $symbol = $this->symbolProvider->getOrInitialize($symbol);

        $candles = $this->candlesProvider->getPreviousCandles($symbol, $timeFrame, 99999999);

        return new JsonResponse($candles);
    }
}
