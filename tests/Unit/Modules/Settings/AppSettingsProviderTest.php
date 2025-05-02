<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Settings;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\Logger\SymfonyAppErrorLogger;
use App\Settings\Application\Service\AppSettingsProvider;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\Dto\SettingValueAccessor;
use App\Stop\Application\Settings\SafePriceDistance;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class AppSettingsProviderTest extends TestCase
{
    private ParameterBagInterface|MockObject $parameterBagInterface;
    private AppSettingsProviderInterface|MockObject $settingsProvider;

    protected function setUp(): void
    {
        $this->parameterBagInterface = $this->createMock(ParameterBagInterface::class);

        $this->settingsProvider = new AppSettingsProvider(
            $this->parameterBagInterface,
            new ArrayAdapter(),
            new SymfonyAppErrorLogger($this->createMock(LoggerInterface::class)),
        );
    }

    /**
     * @dataProvider cases
     */
    public function testGetSettingValueByAccessor(array $existentSettings, SettingValueAccessor $providedAccessor, mixed $expectedValue): void
    {
        $this->mockExistedSettings($existentSettings);

        $result = $this->settingsProvider->get($providedAccessor);

        self::assertEquals($expectedValue, $result);
    }

    public function cases(): iterable
    {
        $existentSettings = [
            'safePriceDistance.percent[symbol=ARCUSDT][side=sell]' => 10,
            'safePriceDistance.percent[symbol=ARCUSDT]' => 20,
            'safePriceDistance.percent' => 30,
        ];

        yield [$existentSettings, SettingValueAccessor::bySide(SafePriceDistance::SafePriceDistance_Percent, Symbol::ARCUSDT, Side::Sell), 10];
        yield [$existentSettings, SettingValueAccessor::bySymbol(SafePriceDistance::SafePriceDistance_Percent, Symbol::ARCUSDT), 20];
        yield [$existentSettings, SettingValueAccessor::simple(SafePriceDistance::SafePriceDistance_Percent), 30];
    }

    public function testWithCache(): void
    {
        $settingValueAccessor = SettingValueAccessor::bySide(SafePriceDistance::SafePriceDistance_Percent, Symbol::ARCUSDT, Side::Sell);
        $values = ['safePriceDistance.percent[symbol=ARCUSDT][side=sell]' => 10];
        $this->mockExistedSettings($values); # with &

        $result = $this->settingsProvider->get($settingValueAccessor, ttl: '1 second');
        self::assertEquals(10, $result);

        $values = ['safePriceDistance.percent[symbol=ARCUSDT][side=sell]' => 30];
        $result = $this->settingsProvider->get($settingValueAccessor);
        self::assertEquals(10, $result);

        sleep(1);
        $values = ['safePriceDistance.percent[symbol=ARCUSDT][side=sell]' => 40];
        $result = $this->settingsProvider->get($settingValueAccessor);
        self::assertEquals(40, $result);
    }

    private function mockExistedSettings(array &$existentSettings): void
    {
        $this->parameterBagInterface->method('get')->willReturnCallback(static function (string $key) use (&$existentSettings) {
            return $existentSettings[$key] ?? null;
        });
    }
}
