<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\Trade\ByBitOrderServiceTest;

use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\DataProvider\TestCaseAwareTest;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers \App\Infrastructure\ByBit\Service\Trade\ByBitOrderService::closeByMarket
 */
final class ClearCacheTest extends KernelTestCase
{
    use PositionSideAwareTest;
    use TestCaseAwareTest;
    use ByBitV5ApiRequestsMocker;

    private const REQUEST_URL = PlaceOrderRequest::URL;
    private const CALLED_METHOD = 'ByBitOrderService::closeByMarket';

    private OrderServiceInterface $orderService;

    protected function setUp(): void
    {
        $this->orderService = self::getContainer()->get(OrderServiceInterface::class);
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testClearPositionCacheOnCloseByMarket(Side $positionSide): void
    {
        $symbol = Symbol::BTCUSDT;
        $initialPositionSize = 1.2;
        $orderQty = 0.01;
        $expectedSizeAfterMarketClose = 1.19;

        $position = PositionBuilder::bySide($positionSide)->symbol($symbol)->size($initialPositionSize)->build();

        # warmup cache and check position size
        $this->assertActualPositionSize($position, $initialPositionSize);

        $this->matchPost(
            PlaceOrderRequest::marketClose($symbol->associatedCategory(), $symbol, $position->side, $orderQty),
            PlaceOrderResponseBuilder::ok(uuid_create())->build(),
        );

        // Act
        $this->orderService->closeByMarket($position, $orderQty);

        // Assert
        $this->assertActualPositionSize($position, $expectedSizeAfterMarketClose);
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testClearPositionCacheOnMarketBuy(Side $positionSide): void
    {
        $symbol = Symbol::BTCUSDT;
        $initialPositionSize = 1.2;
        $orderQty = 0.01;
        $expectedSizeAfterMarketBuy = 1.21;
        $position = PositionBuilder::bySide($positionSide)->symbol($symbol)->size($initialPositionSize)->build();

        # warmup cache and check position size
        $this->assertActualPositionSize($position, $initialPositionSize);

        $this->matchPost(
            PlaceOrderRequest::marketBuy($symbol->associatedCategory(), $symbol, $position->side, $orderQty),
            PlaceOrderResponseBuilder::ok(uuid_create())->build(),
        );

        // Act
        $this->orderService->marketBuy($symbol, $positionSide, $orderQty);

        // Assert
        $this->assertActualPositionSize($position, $expectedSizeAfterMarketBuy);
    }

    private function assertActualPositionSize(Position $position, float $expectedPositionSize): void
    {
        $symbol = $position->symbol;
        $side = $position->side;

        # update "Position API data"
        $position = $position->cloneWithNewSize($expectedPositionSize);
        $this->havePosition($symbol, $position);

        /** @var ByBitLinearPositionCacheDecoratedService $cachedPositionDataProvider */
        $cachedPositionDataProvider = self::getContainer()->get(ByBitLinearPositionCacheDecoratedService::class);

        # Expecting that $this->positionService will make new API call to get new position data
        self::assertEquals($expectedPositionSize, $cachedPositionDataProvider->getPosition($symbol, $side)->size);
    }
}
