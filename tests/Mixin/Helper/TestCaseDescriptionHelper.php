<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Helper;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Domain\Position;

use function sprintf;

trait TestCaseDescriptionHelper
{
    /**
     * @todo | Price | trading symbol price precision
     */
    private static function getPositionCaption(Position $position): string
    {
        $hedge = $position->getHedge();
        if ($hedge) {
            $mainPosition = $hedge->mainPosition;
            $supportPosition = $hedge->supportPosition;

            return sprintf('"%s (%.2f/%.3f/liq=%.2f)" vs "%s (%.2f/%.3f)"', $mainPosition->getCaption(), $mainPosition->entryPrice, $mainPosition->size, $mainPosition->liquidationPrice, $supportPosition->getCaption(), $supportPosition->entryPrice, $supportPosition->size);
        } else {
            return sprintf('"%s (%.2f/%.3f/liq=%.2f)', $position->getCaption(), $position->entryPrice, $position->size, $position->liquidationPrice);
        }
    }

    private static function sandboxTestCaseCaption(SandboxState $initialState, SandboxBuyOrder|SandboxStopOrder $sandboxOrder, SandboxState $newState): string
    {
        return sprintf(
            "\ninitial state: [free=%s, positions=%s] state\n            => [free=%s, positions=%s] state expected [after make %s]\n",
            $initialState->getFreeBalance(),
            self::getPositionCaption($initialState->getMainPosition()),
            $newState->getFreeBalance(),
            self::getPositionCaption($newState->getMainPosition()),
            $sandboxOrder
        );
    }
}
