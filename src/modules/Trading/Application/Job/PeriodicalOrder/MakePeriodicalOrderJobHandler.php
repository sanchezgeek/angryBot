<?php

declare(strict_types=1);

namespace App\Trading\Application\Job\PeriodicalOrder;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Domain\Position\ValueObject\Side;
use App\Helper\DateTimeHelper;
use App\Helper\OutputHelper;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final readonly class MakePeriodicalOrderJobHandler
{
    private const array TASKS = MakePeriodicalOrderJobHandlerTasks::TASKS;

    public function __construct(
        private PeriodicalOrderJobCache $cache,
        private ExchangeServiceInterface $exchangeService,
        private SymbolProvider $symbolProvider,
        private MarketBuyHandler $marketBuyHandler,
    ) {
    }

    public function __invoke(MakePeriodicalOrderJob $job): void
    {
        $now = new DateTimeImmutable();

        foreach (self::TASKS as $key => $task) {
            $force = $task['force'] ?? false;

            $divided = explode(' every ', $task['task']);
            $parsed = explode(' ', $divided[0]);
            $action = $parsed[0];
            $qty = (float)$parsed[1];
            $symbol = $this->symbolProvider->getOrInitialize($parsed[2]);
            $side = Side::from($parsed[3]);
            $period = $divided[1];

            if (!in_array($action, ['stop', 'buy', true])) {
                throw new InvalidArgumentException('action must be one of "buy" or "stop');
            }

            if ($action === 'stop') {
                throw new RuntimeException('`stop` action not implemented yet');
            }

            $period = DateTimeHelper::dateIntervalToSeconds(DateInterval::createFromDateString($period));
            $lastRun = $this->cache->getLastRun($key);
            $haveToRun = !$lastRun || $now->getTimestamp() - $lastRun->getTimestamp() > $period;

            $condition = $task['condition'];
            $evaluateCondition = $this->evaluateCondition($condition, $symbol);

            if ($haveToRun && $evaluateCondition) {
                $success = false;
                $msg = '';
                if ($action === 'buy') {
                    $msg .= sprintf('MakePeriodicalOrderJobHandler for %s: ', sprintf('%s and %s', $task['task'], $task['condition']));
                    try {
                        $this->marketBuyHandler->handle(new MarketBuyEntryDto($symbol, $side, $qty, $force));
                        $success = true;
                    } catch (Throwable $e) {
                        $msg .= implode(', ', [OutputHelper::shortClassName($e), $e->getMessage()]);
                    }
                }

                if ($success) {
                    $this->cache->saveLastRun($key, new DateTimeImmutable());
                }

                if ($msg) {
                    OutputHelper::print($msg);
                }
            }
        }
    }

    private function evaluateCondition(string $condition, SymbolInterface $symbol): bool
    {
        if (str_contains($condition, ':markPrice')) {
            $condition = str_replace(':markPrice', (string)$this->exchangeService->ticker($symbol)->markPrice->value(), $condition);
        }

        eval(sprintf('$res = %s;', $condition));
        /** @var bool $res */

        return $res;
    }
}
