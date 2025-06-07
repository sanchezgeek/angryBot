<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Tests\Assertion\CustomAssertions;
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
        CustomAssertions::assertObjectsWithInnerSymbolsEquals($expectedSymbols, $symbols);
    }

    private function getOpenedPositionsSymbolsTestCases(): iterable
    {
        $category = AssetCategory::linear;

        yield [
            '$apiResponse' => new PositionResponseBuilder($category)->build(),
            '$expectedSymbols' => [],
        ];

        yield [
            '$apiResponse' => new PositionResponseBuilder($category)
                ->withPosition(PositionBuilder::long()->symbol(SymbolEnum::BTCUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(SymbolEnum::BTCUSDT)->build())
                ->withPosition(PositionBuilder::long()->symbol(SymbolEnum::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(SymbolEnum::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(SymbolEnum::AAVEUSDT)->build())
                ->build(),
            '$expectedSymbols' => [SymbolEnum::BTCUSDT, SymbolEnum::XRPUSDT, SymbolEnum::AAVEUSDT],
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
        $symbols = $this->service->getOpenedPositionsSymbols(...$except);

        CustomAssertions::assertObjectsWithInnerSymbolsEquals($expectedSymbols, $symbols);
    }

    private function getOpenedPositionsSymbolsWithExceptTestCases(): iterable
    {
        $category = AssetCategory::linear;

        yield [
            '$except' => [SymbolEnum::BTCUSDT],
            '$apiResponse' => new PositionResponseBuilder($category)->build(),
            '$expectedSymbols' => [],
        ];

        yield [
            '$except' => [SymbolEnum::BTCUSDT],
            '$apiResponse' => new PositionResponseBuilder($category)
                ->withPosition(PositionBuilder::long()->symbol(SymbolEnum::BTCUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(SymbolEnum::BTCUSDT)->build())
                ->withPosition(PositionBuilder::long()->symbol(SymbolEnum::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(SymbolEnum::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(SymbolEnum::AAVEUSDT)->build())
                ->build(),
            '$expectedSymbols' => [SymbolEnum::XRPUSDT, SymbolEnum::AAVEUSDT],
        ];

        yield [
            '$except' => [SymbolEnum::TONUSDT],
            '$apiResponse' => new PositionResponseBuilder($category)
                ->withPosition(PositionBuilder::long()->symbol(SymbolEnum::BTCUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(SymbolEnum::BTCUSDT)->build())
                ->withPosition(PositionBuilder::long()->symbol(SymbolEnum::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(SymbolEnum::XRPUSDT)->build())
                ->withPosition(PositionBuilder::short()->symbol(SymbolEnum::AAVEUSDT)->build())
                ->build(),
            '$expectedSymbols' => [SymbolEnum::BTCUSDT, SymbolEnum::XRPUSDT, SymbolEnum::AAVEUSDT],
        ];
    }
}
