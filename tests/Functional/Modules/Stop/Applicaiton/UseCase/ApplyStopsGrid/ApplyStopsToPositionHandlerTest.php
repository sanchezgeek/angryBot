<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\ApplyStopsGrid;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Order\ExchangeOrder;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionEntryDto;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionHandler;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Mixin\StopsTester;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers ApplyStopsToPositionHandler
 */
final class ApplyStopsToPositionHandlerTest extends KernelTestCase
{
    use StopsTester;

    private ApplyStopsToPositionHandler $handler;

    protected function setUp(): void
    {
        /** @var ApplyStopsToPositionHandler $handler */
        $handler = self::getContainer()->get(ApplyStopsToPositionHandler::class);
        $this->handler = $handler;
    }

    /**
     * @dataProvider cases
     */
    public function testApply(Position $position, string $grid, array $expectedStops): void
    {
        $symbol = $position->symbol;
        $side = $position->side;

        $this->handler->handle(
            new ApplyStopsToPositionEntryDto(
                $symbol, $side, $position->size,
                OrdersGridDefinitionCollection::create($grid, $position->entryPrice(), $side, $symbol)
            )
        );

        self::seeStopsInDb(...$expectedStops);
    }

    public static function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        yield [
            PositionBuilder::short()->symbol($symbol)->entry(100000)->build(),
            '-100%..-200%|30%|5|wOO',
            [
                StopBuilder::short(1, 101000, 0.03, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(2, 101200, 0.03, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(3, 101400, 0.03, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(4, 101600, 0.03, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(5, 101800, 0.03, $symbol)->build()->setIsWithoutOppositeOrder(),
            ],
        ];

        yield [
            PositionBuilder::short()->symbol($symbol)->entry(100000)->size(0.005)->build(),
            '-100%..-200%|30%|1000|wOO',
            [
                StopBuilder::short(1, 101000, 0.001, $symbol)->build()->setIsWithoutOppositeOrder(),
            ],
        ];

        yield [
            PositionBuilder::short()->symbol($symbol)->entry(100000)->size(0.01)->build(),
            '-100%..-200%|30%|1000|wOO',
            [
                StopBuilder::short(1, 101000, 0.001, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(2, 101333.33, 0.001, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(3, 101666.67, 0.001, $symbol)->build()->setIsWithoutOppositeOrder(),
            ],
        ];

        $symbol = SymbolEnum::GRIFFAINUSDT;

        yield [
            PositionBuilder::short()->symbol($symbol)->entry(0.029)->size(4500)->build(),
            '-100%..-1000%|20%|1000|wOO',
            [
                StopBuilder::short(1, 0.02929, 171, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(2, 0.02981, 171, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(3, 0.03033, 171, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(4, 0.03086, 171, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(5, 0.03138, 216, $symbol)->build()->setIsWithoutOppositeOrder(),
            ],
        ];

        yield [
            PositionBuilder::long()->symbol($symbol)->entry(0.029)->size(4500)->build(),
            '-100%..-1000%|20%|1000|wOO',
            [
                StopBuilder::long(1, 0.02871, 192, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::long(2, 0.02806, 192, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::long(3, 0.02741, 192, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::long(4, 0.02675, 324, $symbol)->build()->setIsWithoutOppositeOrder(),
            ],
        ];
    }
}
