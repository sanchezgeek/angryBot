<?php

namespace App\Command\Position\OpenedPositions;

use App\Bot\Domain\Position;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\Position\OpenedPositions\Cache\OpenedPositionsCache;
use App\Command\Position\OpenedPositions\Cache\PositionProxy;
use App\Command\PositionDependentCommand;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Trading\Domain\Symbol\Helper\SymbolHelper;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:opened:save-current-state')]
class SaveCurrentPositionsStateToCacheCommand extends AbstractCommand implements PositionDependentCommand
{
    use SymbolAwareCommand;
    use ConsoleInputAwareCommand;

    private const array AVAILABLE_FIELDS = [
        self::SIZE_FIELD,
    ];

    private const string SELECTED_CACHE = 'cache-item-name';

    private const string SIZE_FIELD = 'size';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->addOption(self::SIZE_FIELD, null, InputOption::VALUE_NEGATABLE)
            ->addOption(self::SELECTED_CACHE, null, InputOption::VALUE_OPTIONAL)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$rootCacheKey = $this->paramFetcher->getStringOption(self::SELECTED_CACHE, false)) {
            assert($savedKeys = $this->cache->getManuallySavedDataCacheKeys(), new Exception('Trying to get last manually saved cache but manually saved cache not found at all'));
            $rootCacheKey = $savedKeys[array_key_last($savedKeys)];
        }
        $cachedData = $this->cache->get($rootCacheKey);

        $changeSize = $this->paramFetcher->getBoolOption(self::SIZE_FIELD);

        $checkCallable = match (true) {
            $changeSize => static fn(PositionProxy|Position $cached, Position $actual) => $cached->size !== $actual->size,
            default => throw new InvalidArgumentException(sprintf('Select one of fields: `%s`', implode('`, `', self::AVAILABLE_FIELDS)))
        };

        $editCallable = match (true) {
            $changeSize => static fn(PositionProxy $proxy, Position $actual) => $proxy->setSize($actual->size),
            default => throw new InvalidArgumentException(sprintf('Select one of fields: `%s`', implode('`, `', self::AVAILABLE_FIELDS)))
        };

        $symbolsFilter = [];
        if ($this->symbolIsSpecified()) {
            $symbolsFilter = SymbolHelper::symbolsToRawValues(...$this->getSymbols());
        }

        $positions = $this->positionService->getAllPositions();
        foreach ($positions as $symbolRaw => $symbolPositions) {
            if ($symbolsFilter && !in_array($symbolRaw, $symbolsFilter, true)) {
                continue;
            }

            foreach ($symbolPositions as $position) {
                $positionDataKey = OpenedPositionsCache::positionDataKey($position);

                if (!$cached = $cachedData[$positionDataKey] ?? null) {
                    continue;
                } // if (!$cachedPosition = $this->cache->getCachedPositionItem($position)) continue;

                if (!$checkCallable($cached, $position)) {
                    continue;
                }

                $proxy = $cached instanceof PositionProxy ? $cached : new PositionProxy($cached);

                $editCallable($proxy, $position);

                $cachedData[$positionDataKey] = $proxy; // $this->cache->addToCache();
            }
        }

        $this->cache->saveToCache($rootCacheKey, $cachedData);

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ByBitLinearPositionService $positionService,
        private readonly OpenedPositionsCache $cache,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
