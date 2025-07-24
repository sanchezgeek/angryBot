<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Trading\Applicaiton\Order\ContextShortcut;

use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Trading\Application\Order\ContextShortcut\ContextShortcutRootProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContextShortcutRootProcessorTest extends KernelTestCase
{
    use TestWithDbFixtures;

    private ContextShortcutRootProcessor $rootContextProcessor;
    private BuyOrderRepository $buyOrderRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootContextProcessor = self::getContainer()->get(ContextShortcutRootProcessor::class);
        $this->buyOrderRepository = self::getContainer()->get(BuyOrderRepository::class);
    }

    public function testFinalContext(): void
    {
        $context = $this->rootContextProcessor->getResultContextArray(['sAPc', 'sFLc', 'o=300.5%'], OrderType::Add);

        self::assertEquals(
            [
                'checks' => [
                    'skipAveragePriceCheck' => true,
                    'skipFurtherLiquidationCheck' => true,
                ],
                'oppositeOrdersDistance' => '300.500%',
            ],
            $context
        );
    }
}
