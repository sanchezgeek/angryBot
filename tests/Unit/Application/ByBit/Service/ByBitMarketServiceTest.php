<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\ByBit\Service;

use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\Service\ByBitMarketService;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;

use function date_create_immutable;
use function strtotime;

class ByBitMarketServiceTest extends TestCase
{
    private ClockInterface $clockMock;
    private ByBitMarketService $service;

    protected function setUp(): void
    {
        $this->clockMock = $this->createMock(ClockInterface::class);

        $this->service = new ByBitMarketService(
            $this->clockMock,
            $this->createMock(ByBitApiClientInterface::class)
        );
    }

    /**
     * @dataProvider isNowFundingFeesPaymentTimeTestCases
     */
    public function testIsNowFundingFeesPaymentTime(DateTimeInterface $now, bool $expectedResult): void
    {
        $this->clockMock->expects(self::once())->method('now')->willReturn($now);

        $result = $this->service->isNowFundingFeesPaymentTime();

        self::assertEquals($expectedResult, $result);
    }

    public function isNowFundingFeesPaymentTimeTestCases(): array
    {
        return [
            ['now' => date_create_immutable('2023-12-10 23:48:47'), false],
            ['now' => date_create_immutable('2023-12-10 23:59:44'), false],
            ['now' => date_create_immutable('2023-12-10 23:59:45'), true],
            ['now' => date_create_immutable('2023-12-10 00:00:00'), true],
            ['now' => date_create_immutable('2023-12-10 00:00:01'), true],
            ['now' => date_create_immutable('2023-12-10 00:00:35'), true],
            ['now' => date_create_immutable('2023-12-10 00:00:59'), true],
            ['now' => date_create_immutable('2023-12-10 00:01:00'), true],
            ['now' => date_create_immutable('2023-12-10 00:01:01'), false],
            ['now' => date_create_immutable('2023-12-10 02:01:01'), false],
            ['now' => date_create_immutable('2023-12-10 03:01:01'), false],
            ['now' => date_create_immutable('2023-12-10 04:01:01'), false],
            ['now' => date_create_immutable('2023-12-10 05:01:01'), false],
            ['now' => date_create_immutable('2023-12-10 06:01:01'), false],
            ['now' => date_create_immutable('2023-12-10 07:01:01'), false],
            ['now' => date_create_immutable('2023-12-10 08:01:01'), false],
            ['now' => date_create_immutable('2023-12-10 09:01:01'), false],
            ['now' => date_create_immutable('2023-12-10 10:01:01'), false],

            ['now' => date_create_immutable('2023-12-10 07:48:47'), false],
            ['now' => date_create_immutable('2023-12-10 07:59:44'), false],
            ['now' => date_create_immutable('2023-12-10 07:59:45'), true],
            ['now' => date_create_immutable('2023-12-10 08:00:00'), true],
            ['now' => date_create_immutable('2023-12-10 08:00:01'), true],
            ['now' => date_create_immutable('2023-12-10 08:00:35'), true],
            ['now' => date_create_immutable('2023-12-10 08:00:59'), true],
            ['now' => date_create_immutable('2023-12-10 08:01:00'), true],
            ['now' => date_create_immutable('2023-12-10 08:01:01'), false],

            ['now' => date_create_immutable('2023-12-10 12:48:47'), false],
            ['now' => date_create_immutable('2023-12-10 15:48:47'), false],
            ['now' => date_create_immutable('2023-12-10 15:59:44'), false],
            ['now' => date_create_immutable('2023-12-10 15:59:45'), true],
            ['now' => date_create_immutable('2023-12-10 16:00:00'), true],
            ['now' => date_create_immutable('2023-12-10 16:00:01'), true],
            ['now' => date_create_immutable('2023-12-10 16:00:35'), true],
            ['now' => date_create_immutable('2023-12-10 16:00:59'), true],
            ['now' => date_create_immutable('2023-12-10 16:01:00'), true],
            ['now' => date_create_immutable('2023-12-10 16:01:01'), false],
        ];
    }
}
