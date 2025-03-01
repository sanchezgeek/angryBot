<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Messenger\Trading\CoverLossesAfterCloseByMarketConsumer;

use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumer;
use App\Bot\Application\Settings\PushStopSettings;
use App\Settings\Application\Service\AppSettingsProvider;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class CoverLossesAfterCloseByMarketConsumerTestAbstract extends KernelTestCase
{
    use ByBitV5ApiRequestsMocker;
    use SettingsAwareTest;

    protected CoverLossesAfterCloseByMarketConsumer $consumer;
    protected AppSettingsProvider $settingsProvider;

    protected function setUp(): void
    {
        $this->consumer = self::getContainer()->get(CoverLossesAfterCloseByMarketConsumer::class);

        # enable
        $this->settingsProvider = self::getContainerSettingsProvider();
        $this->settingsProvider->set(PushStopSettings::Cover_Loss_After_Close_By_Market, true);
    }
}
