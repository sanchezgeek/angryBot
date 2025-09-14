<?php

declare(strict_types=1);

namespace App\Chart\UI\Symfony\Controller;

use App\Chart\Application\Service\CandlesProvider;
use App\Domain\Trading\Enum\TimeFrame;
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

    #[Route(path: '/candles/list/{symbol}/{timeFrame}', requirements: ['symbol' => '\w+', 'timeFrame' => '\w+'])]
    public function symbols(string $symbol, string $timeFrame): Response
    {
        $timeFrame = TimeFrame::from($timeFrame);
        $symbolParsed = $this->symbolProvider->getOrInitialize($symbol);

        $to = date_create_immutable();
        $from = $to->sub(new DateInterval('P100D'));

        return new JsonResponse(
            $this->candlesProvider->getCandles($symbolParsed, $timeFrame, $from, $to)
        );
    }
}
