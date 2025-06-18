<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Application\Service\RestoreOrder;

use App\Bot\Application\Service\RestoreOrder\StopRestoreFactory;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Assertion\CustomAssertions;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\StopsTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StopRestoreFactoryTest extends KernelTestCase
{
    use PositionSideAwareTest;
    use StopsTester;

    private readonly StopRestoreFactory $stopRestoreFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stopRestoreFactory = self::getContainer()->get(StopRestoreFactory::class);
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testRestore(Side $positionSide): void
    {
        $data = [
            'id' => $id = 100500,
            'positionSide' => $positionSide->value,
            'symbol' => SymbolEnum::BTCUSDT->value,
            'price' => $price = 29000.1,
            'volume' => $volume = 0.011,
            'triggerDelta' => $triggerDelta = 13.1,
            'context' => $context = [
                'root.string.context' => 'some string context',
                'root.bool.context' => false,
                'some.array.context' => [
                    'inner.string.context' => 'some string context',
                    'inner.bool.context' => true,
                ],
            ]
        ];

        CustomAssertions::assertObjectsWithInnerSymbolsEquals(
            [new Stop(null, $price, $volume, $triggerDelta, SymbolEnum::BTCUSDT, $positionSide, $context)],
            [$this->stopRestoreFactory->restore($data)]
        );
    }
}
