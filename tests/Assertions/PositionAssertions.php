<?php

declare(strict_types=1);

namespace App\Tests\Assertions;

use App\Bot\Domain\Position;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;

final class PositionAssertions extends Assert
{
    /**
     * @param Position[] $expectedPositions
     * @param Position[] $actualPositions
     */
    public static function assertPositionsEquals(array $expectedPositions, array $actualPositions): void
    {
        foreach ($expectedPositions as $position) {
            if ($position !== null) {
                $position->initializeHedge();
                $position->oppositePosition?->initializeHedge();
            }
        }
        foreach ($actualPositions as $position) {
            if ($position !== null) {
                $position->initializeHedge();
                $position->oppositePosition?->initializeHedge();
            }
        }

        foreach ($expectedPositions as $key => $expected) {
            if ($expected !== null && !($actual = $actualPositions[$key] ?? null)) {
                throw new ExpectationFailedException('Failed to find corresponded Position in $actualPositons array');
            }

            if (!isset($expected) && !isset($actual)) {
                continue;
            }

            assert($expected->symbol->name() === $actual->symbol->name(), new ExpectationFailedException('Symbols not equal'));

            if ($expected->oppositePosition !== null) {
                assert($actual->oppositePosition !== null, new ExpectationFailedException('Opposite positions not equal'));
                assert($actual->oppositePosition->symbol->name() === $expected->oppositePosition->symbol->name(), new ExpectationFailedException('Opposite positions not equal'));

                $expectedOppositeVars = get_object_vars($expected->oppositePosition);
                $actualOppositeVars = get_object_vars($actual->oppositePosition);

                unset(
                    $actualOppositeVars['symbol'],
                    $actualOppositeVars['oppositePosition'],
                    $expectedOppositeVars['symbol'],
                    $expectedOppositeVars['oppositePosition'],
                );

                self::assertEquals($expectedOppositeVars, $actualOppositeVars);
            }

            $expectedVars = get_object_vars($expected);
            $actualVars = get_object_vars($actual);

            unset(
                $expectedVars['symbol'],
                $expectedVars['oppositePosition'],
                $actualVars['symbol'],
                $actualVars['oppositePosition']
            );

            self::assertEquals($expectedVars, $actualVars);
        }
    }
}
