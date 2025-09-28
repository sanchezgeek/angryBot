<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Orders;

use App\Bot\Application\Service\Orders\Dto\CreatedIncGridInfo;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\SymbolPrice;
use App\Stop\Application\Contract\Command\CreateStop;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trait\DispatchCommandTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class StopService implements StopServiceInterface
{
    use DispatchCommandTrait;

    private const int DEFAULT_STEP = 11;
    private const int DEFAULT_TRIGGER_DELTA = 3;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function create(
        SymbolInterface $symbol,
        Side $positionSide,
        SymbolPrice|float $price,
        float $volume,
        ?float $triggerDelta = null,
        array $context = [],
        bool $dryRun = false
    ): Stop {
        // @todo По хорошему тут должна быть защита: если ужё всё под стопами - то нельзя создавать
        // Но не переборщить
        // + может быть job, который будет как-то хитро делать rearrange

        return $this->dispatchCommand(
            new CreateStop(
                symbol: $symbol,
                positionSide: $positionSide,
                volume: $volume,
                price: $price instanceof SymbolPrice ? $price->value() : $price,
                triggerDelta: $triggerDelta,
                context: $context,
                dryRun: $dryRun,
            ),
        );
    }

    /**
     * @todo | symbol
     */
    public function createIncrementalToPosition(
        Position $position,
        float $volume,
        float $fromPrice,
        float $toPrice,
        array $context = []
    ): CreatedIncGridInfo {
        $symbol = $position->symbol;
        $context['uniqid'] = \uniqid('inc-stop', true);

        $delta = abs($fromPrice - $toPrice);
        $step = self::DEFAULT_STEP;

        $count = \ceil($delta / $step);
        if ($volume / $count < $symbol->minOrderQty()) {
            $count = $volume / $symbol->minOrderQty();
            $step = PriceHelper::round($delta / $count);

            $stepVolume = $symbol->minOrderQty();
        } else {
            $stepVolume = $symbol->roundVolume($volume / $count);
        }

        $price = $fromPrice;

        $info = new CreatedIncGridInfo([
            'volume' => $volume,
            'fromPrice' => $fromPrice,
            'toPrice' => $toPrice,
            'delta' => PriceHelper::round($delta),
            'step' => PriceHelper::round($step),
            'count' => $count,
            'stepVolume' => $stepVolume,
            'uniqueID' => $context['uniqid'],
        ]);

        do {
            $price += $position->side === Side::Sell ? ($step) : (-$step);

            $this->create(
                $position->symbol,
                $position->side,
                $price,
                $stepVolume,
                self::DEFAULT_TRIGGER_DELTA,
                $context
            );

            $volume -= $stepVolume;
        } while ($volume > 0);
//        } while ($stopVolume >= $stepVolume && $price >= $toPrice);

        return $info;
    }
}
