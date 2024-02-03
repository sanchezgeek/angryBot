<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Orders;

use App\Bot\Application\Command\CreateStop;
use App\Bot\Application\Service\Orders\Dto\CreatedIncGridInfo;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Helper\VolumeHelper;
use App\Trait\DispatchCommandTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class StopService implements StopServiceInterface
{
    use DispatchCommandTrait;

    // private const DEFAULT_INC = 0.001;
    private const DEFAULT_STEP = 11;
    private const DEFAULT_TRIGGER_DELTA = 3;

    public function __construct(
        private readonly StopRepository $repository,
        MessageBusInterface $commandBus,
    ) {
        $this->commandBus = $commandBus;
    }

    public function create(Side $positionSide, float $price, float $volume, float $triggerDelta, array $context = []): int
    {
        // @todo По хорошему тут должна быть защита: если ужё всё под стопами - то нельзя создавать
        // Но не переборщить
        // + может быть job, который будет как-то хитро делать rearrange

        $id = $this->repository->getNextId();

        $this->dispatchCommand(
            new CreateStop(
                $id,
                $positionSide,
                $volume,
                $price,
                $triggerDelta,
                $context
            ),
        );

        return $id;
    }

    public function createIncrementalToPosition(
        Position $position,
        float $volume,
        float $fromPrice,
        float $toPrice,
        array $context = []
    ): CreatedIncGridInfo {
        $context['uniqid'] = \uniqid('inc-stop', true);

        $delta = abs($fromPrice - $toPrice);
        $step = self::DEFAULT_STEP;

        $count = \ceil($delta / $step);
        if ($volume / $count < VolumeHelper::MIN_VOLUME) {
            $count = $volume / VolumeHelper::MIN_VOLUME;
            $step = PriceHelper::round($delta / $count);

            $stepVolume = VolumeHelper::MIN_VOLUME;
        } else {
            $stepVolume = VolumeHelper::round($volume / $count);
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
