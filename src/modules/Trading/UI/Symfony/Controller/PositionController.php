<?php

declare(strict_types=1);

namespace App\Trading\UI\Symfony\Controller;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Factory\PositionFactory;
use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Trading\Api\View\OpenedPositionInfoView;
use App\Trading\Application\Symbol\SymbolProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PositionController extends AbstractController
{
    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly ByBitLinearPositionService $positionServiceWithoutCache,
        private readonly SymbolProvider $symbolProvider,
    ) {
    }

    #[Route(path: '/opened-positions/{symbol}', requirements: ['symbol' => '\w+'])]
    public function openedPositions(?string $symbol = ''): Response
    {
        $symbol = $symbol ? $this->symbolProvider->getOrInitialize($symbol) : null;

        if ($symbol) {
            $result = array_map([$this, 'mapToView'], $this->positionService->getPositions($symbol));
        } else {
            if (!$allPositions = $this->positionServiceWithoutCache->getAllPositions()) {
                $result = [];
            } else {
                $positions = [];
                foreach ($allPositions as $symbolPositions) {
                    $positions = array_merge($positions, array_values($symbolPositions));
                }

                $result = array_map([$this, 'mapToView'], $positions);
            }
        }

//        $positions = [PositionFactory::fakeWithNoLiquidation($symbol, Side::Sell, 0.15)];
//        $result = array_map([$this, 'mapToView'], $positions);

        return new JsonResponse($result);
    }

    private function mapToView(Position $position): OpenedPositionInfoView
    {
        return new OpenedPositionInfoView($position);
    }
}
