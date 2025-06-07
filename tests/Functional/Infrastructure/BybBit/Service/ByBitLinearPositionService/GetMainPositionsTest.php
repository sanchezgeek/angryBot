<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Tests\Assertions\PositionAssertions;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBitV5Api\PositionResponseBuilder;
use App\Tests\PHPUnit\TestLogger;
use App\Trading\Application\Symbol\SymbolProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GetMainPositionsTest extends KernelTestCase
{
    use ByBitV5ApiTester;

    const AssetCategory CATEGORY = AssetCategory::linear;

    private LoggerInterface $logger;
    protected ByBitLinearPositionService $service;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();

        $this->service = new ByBitLinearPositionService(
            $this->initializeApiClient(),
            new ArrayAdapter(),
            self::getContainer()->get(SymbolProvider::class),
        );
    }

    /**
     * @dataProvider cases
     */
    public function testGetMainPositions(MockResponse $apiResponse, array $expectedResult): void
    {
        $this->matchGet(new GetPositionsRequest(self::CATEGORY, null), $apiResponse);
        $result = $this->service->getPositionsWithLiquidation();

        PositionAssertions::assertPositionsEquals($expectedResult, $result);
    }

    public function cases(): iterable
    {
        $category = self::CATEGORY;

        yield [
            '$apiResponse' => (new PositionResponseBuilder($category))->build(),
            '$expectedSymbols' => [],
        ];

        $ethusdtLong = PositionBuilder::long()->symbol(SymbolEnum::ETHUSDT)->size(1.1)->entry(2050)->build();

        $btcusdtLong = PositionBuilder::long()->symbol(SymbolEnum::BTCUSDT)->size(1)->unrealizedPnl(1)->entry(123456)->build();
        $btcusdtShort = PositionBuilder::short()->symbol(SymbolEnum::BTCUSDT)->size(0.5)->unrealizedPnl(1)->build($btcusdtLong);

        $linkusdtLong = PositionBuilder::long()->symbol(SymbolEnum::LINKUSDT)->size(10)->entry(25)->build();
        $linkusdtShort = PositionBuilder::short()->symbol(SymbolEnum::LINKUSDT)->size(10)->entry(35)->build($linkusdtLong);

        yield [
            '$apiResponse' => (new PositionResponseBuilder($category))
                ->withPosition($ethusdtLong)
                ->withPosition($btcusdtLong)->withPosition($btcusdtShort)
                ->withPosition($linkusdtLong)->withPosition($linkusdtShort)
                ->build(),
            '$expectedResult' => [$ethusdtLong, $btcusdtLong],
        ];
    }
}
