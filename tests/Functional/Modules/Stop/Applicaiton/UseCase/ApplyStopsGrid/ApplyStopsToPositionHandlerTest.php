<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\ApplyStopsGrid;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionEntryDto;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionHandler;
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
    public function testApply(Position $position, string $grid, callable $assertion): void
    {
        $symbol = $position->symbol;
        $side = $position->side;

        $this->handler->handle(
            new ApplyStopsToPositionEntryDto(
                $symbol, $side, $position->size,
                OrdersGridDefinitionCollection::create($grid, $position->entryPrice(), $side, $symbol)
            )
        );

        $stops = self::getCurrentStopsSnapshot();

        $assertion($stops);

        self::assertTrue(true);
    }

    public static function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $position = PositionBuilder::short()->symbol($symbol)->entry(100000)->build();

        yield [
            $position,
            '-100%..-200%|30%|5|wOO',
            static function (array $stopsInDb): void {
                /** @var Stop[] $stopsInDb */
                foreach ($stopsInDb as $stop) {
                    assert(!$stop->isWithOppositeOrder(), sprintf('stop[id = %d] is not without opposite order', $stop->getId()));
                }
            }
        ];
    }
}
