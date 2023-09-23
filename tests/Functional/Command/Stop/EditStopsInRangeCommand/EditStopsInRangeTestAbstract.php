<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command\Stop\EditStopsInRangeCommand;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Tests\Stub\Bot\PositionServiceStub;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

abstract class EditStopsInRangeTestAbstract extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;

    protected const COMMAND_NAME = 'sl:range-edit';

    protected PositionServiceStub $positionServiceStub;
    protected CommandTester $tester;

    protected function setUp(): void
    {
        $this->positionServiceStub = self::getContainer()->get(PositionServiceInterface::class);
        $this->tester = new CommandTester((new Application(self::$kernel))->find(self::COMMAND_NAME));

        self::truncateStops();
    }
}
