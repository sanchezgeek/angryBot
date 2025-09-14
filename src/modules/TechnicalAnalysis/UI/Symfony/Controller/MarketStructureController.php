<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\UI\Symfony\Controller;

use App\Bot\Domain\Position;
use App\Chart\Application\Service\CandlesProvider;
use App\Domain\Trading\Enum\TimeFrame;
use App\TechnicalAnalysis\Application\MarketStructure\ZigZagFinder;
use App\TechnicalAnalysis\Application\MarketStructure\ZigZagService;
use App\Trading\Api\View\OpenedPositionInfoView;
use App\Trading\Application\Symbol\SymbolProvider;
use DateInterval;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketStructureController extends AbstractController
{
    public function __construct(
        private readonly CandlesProvider $candlesProvider,
        private readonly SymbolProvider $symbolProvider,
    ) {
    }

    #[Route(path: '/market-structure/{symbol}/{timeFrame}', requirements: ['symbol' => '\w+', 'timeFrame' => '\w+'])]
    public function structure(string $symbol, string $timeFrame): Response
    {
        $timeFrame = TimeFrame::from($timeFrame);

        $symbol = $symbol ? $this->symbolProvider->getOrInitialize($symbol) : null;

        $to = date_create_immutable();
        $from = $to->sub(new DateInterval('P100D'));

        $candles = $this->candlesProvider->getCandles($symbol, $timeFrame, $from, $to);

        $service = new ZigZagService(new ZigZagFinder());
        $points = $service->findZigZagPoints($candles);

        return new JsonResponse([
            'points' => $points
        ]);
    }

    private function mapToView(Position $position): OpenedPositionInfoView
    {
        return new OpenedPositionInfoView($position);
    }
}
