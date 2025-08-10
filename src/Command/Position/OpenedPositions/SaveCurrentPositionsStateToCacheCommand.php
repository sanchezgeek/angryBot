<?php

namespace App\Command\Position\OpenedPositions;

use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\Position\OpenedPositions\Cache\OpenedPositionsCache;
use App\Command\Position\OpenedPositions\Cache\PositionProxy;
use App\Command\SymbolDependentCommand;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Trading\Domain\Symbol\Helper\SymbolHelper;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:opened:cache:replace-with-current')]
class SaveCurrentPositionsStateToCacheCommand extends AbstractCommand implements SymbolDependentCommand
{
    use SymbolAwareCommand;
    use ConsoleInputAwareCommand;

    private const string SELECTED_CACHE = 'cache-item-name';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->addOption(self::SELECTED_CACHE, null, InputOption::VALUE_OPTIONAL)
        ;

        foreach (PositionProxy::getAvailableReplacements() as $replacement) {
            $this->addOption($replacement, null, InputOption::VALUE_NEGATABLE);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$rootCacheKey = $this->paramFetcher->getStringOption(self::SELECTED_CACHE, false)) {
            assert($savedKeys = $this->cache->getManuallySavedDataCacheKeys(), new Exception('Trying to get last manually saved cache but manually saved cache not found at all'));
            $rootCacheKey = $savedKeys[array_key_last($savedKeys)];
        }
        $cachedData = $this->cache->get($rootCacheKey);

        $symbolsFilter = $this->symbolIsSpecified() ? SymbolHelper::symbolsToRawValues(...$this->getSymbols()) : [];

        foreach ($this->positionService->getAllPositions() as $symbolRaw => $symbolPositions) {
            if ($symbolsFilter && !in_array($symbolRaw, $symbolsFilter, true)) {
                continue;
            }

            foreach ($symbolPositions as $position) {
                $positionDataKey = OpenedPositionsCache::positionDataKey($position);

                if (!$cached = $cachedData[$positionDataKey] ?? null) {
                    continue;
                } // if (!$cachedPosition = $this->cache->getCachedPositionItem($position)) continue;

                $proxy = $cached instanceof PositionProxy ? $cached : new PositionProxy($cached);
                $initial = clone $proxy;

                foreach (PositionProxy::getAvailableReplacements() as $replacement) {
                    if ($this->paramFetcher->getBoolOption($replacement)) {
                        $proxy->replace($replacement, $position->{$replacement});
                    }
                }

                if ($proxy->hasChangesWith($initial)) {
                    $cachedData[$positionDataKey] = $proxy; // $this->cache->addToCache();
                }
            }
        }

        $this->cache->saveToCache($rootCacheKey, $cachedData);

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly OpenedPositionsCache $cache,
        private readonly ByBitLinearPositionService $positionService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
