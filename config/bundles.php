<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\MakerBundle\MakerBundle::class => ['dev' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    \App\Profiling\ProfilingModule::class => ['all' => true],
    \App\Alarm\AlarmModule::class => ['all' => true],
    \App\Screener\ScreenerModule::class => ['all' => true],
    \App\Bot\BotBundle::class => ['all' => true],
    \App\Stop\StopModule::class => ['all' => true],
    \App\Buy\BuyModule::class => ['all' => true],
    \App\Liquidation\LiquidationModule::class => ['all' => true],
    \App\Trading\TradingBundle::class => ['all' => true],
    \App\Watch\WatchBundle::class => ['all' => true],
];
