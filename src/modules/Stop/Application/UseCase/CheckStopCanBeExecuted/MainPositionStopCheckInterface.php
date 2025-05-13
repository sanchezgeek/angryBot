<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

/**
 * Warning!
 * All implementations must be well tested.
 * Reason: failed check result will lead PushStopsHandler to ignore stop and possible POSITION LIQUIDATION
 *
 * @see \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler::stopCanBePushed
 */
interface MainPositionStopCheckInterface
{
}
