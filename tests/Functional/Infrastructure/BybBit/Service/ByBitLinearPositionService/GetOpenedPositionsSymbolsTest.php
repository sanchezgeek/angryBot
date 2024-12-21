<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Mock\Response\ByBitV5Api\PositionResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @covers \App\Infrastructure\ByBit\Service\ByBitLinearPositionService::getOpenedPositionsSymbols
 */
final class GetOpenedPositionsSymbolsTest extends ByBitLinearPositionServiceTestAbstract
{
    /**
     * @dataProvider getOpenedPositionsSymbolsTestCases
     */
    public function testGetOpenedPositionsSymbols(
        MockResponse $apiResponse,
        array $expectedSymbols
    ): void {
        $this->matchGet(new GetPositionsRequest(AssetCategory::linear, null), $apiResponse);
        $symbols = $this->service->getOpenedPositionsSymbols();
        self::assertEquals($expectedSymbols, $symbols);
    }

    private function getOpenedPositionsSymbolsTestCases(): iterable
    {
        $category = AssetCategory::linear;

        yield [
            '$apiResponse' => (new PositionResponseBuilder($category))->build(),
            '$expectedSymbols' => [],
        ];

        yield [
            '$apiResponse' => (new PositionResponseBuilder($category))
                ->withPosition(PositionBuilder::long()->symbol(Symbol::BTCUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(Symbol::BTCUSDT)->build())
                ->withPosition(PositionBuilder::long()->symbol(Symbol::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(Symbol::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(Symbol::AAVEUSDT)->build())
                ->build(),
            '$expectedSymbols' => [Symbol::BTCUSDT, Symbol::XRPUSDT, Symbol::AAVEUSDT],
        ];
    }

    /**
     * @dataProvider getOpenedPositionsSymbolsWithExceptTestCases
     */
    public function testGetOpenedPositionsSymbolsWithExcept(
        array $except,
        MockResponse $apiResponse,
        array $expectedSymbols
    ): void {
        $this->matchGet(new GetPositionsRequest(AssetCategory::linear, null), $apiResponse);
        $symbols = $this->service->getOpenedPositionsSymbols($except);

        self::assertEquals($expectedSymbols, $symbols);
    }

    private function getOpenedPositionsSymbolsWithExceptTestCases(): iterable
    {
        $category = AssetCategory::linear;

        yield [
            '$except' => [Symbol::BTCUSDT],
            '$apiResponse' => (new PositionResponseBuilder($category))->build(),
            '$expectedSymbols' => [],
        ];

        yield [
            '$except' => [Symbol::BTCUSDT],
            '$apiResponse' => (new PositionResponseBuilder($category))
                ->withPosition(PositionBuilder::long()->symbol(Symbol::BTCUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(Symbol::BTCUSDT)->build())
                ->withPosition(PositionBuilder::long()->symbol(Symbol::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(Symbol::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(Symbol::AAVEUSDT)->build())
                ->build(),
            '$expectedSymbols' => [Symbol::XRPUSDT, Symbol::AAVEUSDT],
        ];

        yield [
            '$except' => [Symbol::TONUSDT],
            '$apiResponse' => (new PositionResponseBuilder($category))
                ->withPosition(PositionBuilder::long()->symbol(Symbol::BTCUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(Symbol::BTCUSDT)->build())
                ->withPosition(PositionBuilder::long()->symbol(Symbol::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(Symbol::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(Symbol::AAVEUSDT)->build())
                ->build(),
            '$expectedSymbols' => [Symbol::BTCUSDT, Symbol::XRPUSDT, Symbol::AAVEUSDT],
        ];
    }
}
