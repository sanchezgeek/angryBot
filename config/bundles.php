<?php

return [
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\MakerBundle\MakerBundle::class => ['dev' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Pentatrion\ViteBundle\PentatrionViteBundle::class => ['all' => true],

    App\Profiling\ProfilingModule::class => ['all' => true],
    App\Alarm\AlarmModule::class => ['all' => true],
    App\Screener\ScreenerModule::class => ['all' => true],
    App\Bot\BotBundle::class => ['all' => true],
    App\Stop\StopModule::class => ['all' => true],
    App\Liquidation\LiquidationModule::class => ['all' => true],
    App\Trading\TradingBundle::class => ['all' => true],
];
