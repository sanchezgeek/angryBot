<?php

declare(strict_types=1);

namespace App\Chart\UI\Symfony\Controller;

use App\Bot\Domain\ValueObject\Symbol;
use App\Chart\Application\CandlesProvider;
use DateInterval;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CandlesApiController extends AbstractController
{
    public function __construct(
        private readonly CandlesProvider $candlesProvider
    ) {
    }

    #[Route(path: '/candles/list/{symbol}', requirements: ['symbol' => '\w+'])]
    public function openedPositions(?string $symbol = null): Response
    {
        if (!$symbolParsed = Symbol::tryFrom($symbol)) {
            throw new InvalidArgumentException(sprintf('Cannot fetch symbol from %s', $symbol));
        }

        $to = date_create_immutable();
        $from = $to->sub(new DateInterval('P1D'));

        return new JsonResponse(
            $this->candlesProvider->getCandles($symbolParsed, $from, $to)
        );
    }
}
