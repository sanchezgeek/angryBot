<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionServiceTestAbstract;
use App\Tests\Mock\Response\ByBit\PositionResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;

use function sprintf;

/**
 * @covers \App\Infrastructure\ByBit\ByBitLinearPositionService
 */
final class GetPositionTest extends ByBitLinearPositionServiceTestAbstract
{
    /**
     * @dataProvider getPositionTestCases
     */
    public function testGetPosition(
        Symbol $symbol,
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
        self::assertEquals($expectedPosition, $position);
    }

    private function getPositionTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        yield sprintf('have %s %s position (%s)', $symbol->value, $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->addPosition(
                $symbol,
                $positionSide,
                $entryPrice = 30000,
                $size = 1.1,
                $value = 33000,
                $margin = 330,
                $leverage = 100,
                $liqPrice = 31000,
            )->build(),
            '$expectedPosition' => new Position(
                $positionSide,
                $symbol,
                $entryPrice,
                $size,
                $value,
                $liqPrice,
                $margin,
                $leverage,
            ),
        ];

        yield sprintf('have no position (%s %s, %s)', $symbol->value, $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->build(),
            '$expectedPosition' => null,
        ];
    }
}
