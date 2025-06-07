<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Tests\Assertion\CustomAssertions;
use App\Tests\Assertions\PositionAssertions;
use App\Tests\Mock\Response\ByBitV5Api\PositionResponseBuilder;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function sprintf;

/**
 * @covers \App\Infrastructure\ByBit\Service\ByBitLinearPositionService::getPosition
 * @todo | test ::getPositions
 */
final class GetPositionTest extends ByBitLinearPositionServiceTestAbstract
{
    /**
     * @dataProvider getPositionTestCases
     */
    public function testGetPosition(
        SymbolInterface $symbol,
        AssetCategory $category,
        Side $positionSide,
        MockResponse $apiResponse,
        ?Position $expectedPosition
    ): void {
        // Arrange
        $this->matchGet(new GetPositionsRequest($category, $symbol), $apiResponse);

        // Act
        $position = $this->service->getPosition($symbol, $positionSide);

        // Assert
        PositionAssertions::assertPositionsEquals([$expectedPosition], [$position]);
    }

    private function getPositionTestCases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $category = AssetCategory::linear;

        ### SHORT ###
        $positionSide = Side::Sell;

        # single
        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 31000, 330, 100, -20);
        yield sprintf('have only %s %s position (%s)', $symbol->name(), $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->withPosition($position)->build(),
            '$expectedPosition' => $position,
        ];

        # with opposite
        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 31000, 330, 100, -20);
        $oppositePosition = new Position($positionSide->getOpposite(), $symbol, 29000, 1.2, 31000, 0.0, 200, 90, 100);
        $position->setOppositePosition($oppositePosition);
        $oppositePosition->setOppositePosition($position);
        yield sprintf('have %s %s position with opposite (%s)', $symbol->name(), $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->withPosition($position)->withPosition($oppositePosition)->build(),
            '$expectedPosition' => $position,
        ];

        # have no position on SHORT side
        $oppositePosition = new Position($positionSide->getOpposite(), $symbol, 29000, 1.2, 31000, 0.0, 200, 90, 100);
        yield sprintf('have no %s position on %s side (%s)', $symbol->name(), $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->withPosition($oppositePosition)->build(),
            '$expectedPosition' => null,
        ];

        ### BUY ###
        $positionSide = Side::Buy;

        # single
        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 29000, 330, 100, 20);
        yield sprintf('have only %s %s position (%s)', $symbol->name(), $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->withPosition($position)->build(),
            '$expectedPosition' => $position,
        ];

        # with opposite
        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 29000, 330, 100, 20);
        $oppositePosition = new Position($positionSide->getOpposite(), $symbol, 30000, 0.8, 28000, 0.0, 100, 90, -20);
        $position->setOppositePosition($oppositePosition);
        $oppositePosition->setOppositePosition($position);
        yield sprintf('have %s %s position with opposite (%s)', $symbol->name(), $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->withPosition($position)->withPosition($oppositePosition)->build(),
            '$expectedPosition' => $position,
        ];

        # have no position on LONG side
        $oppositePosition = new Position($positionSide->getOpposite(), $symbol, 30000, 0.8, 28000, 0.0, 100, 90, -20);
        yield sprintf('have no %s position on %s side (%s)', $symbol->name(), $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->withPosition($oppositePosition)->build(),
            '$expectedPosition' => null,
        ];
    }
}
