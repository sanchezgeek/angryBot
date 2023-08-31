<?php

namespace App\Command\Mixin;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;

use function sprintf;

trait PositionAwareCommand
{
    use ConsoleInputAwareCommand;

    private const POSITION_SIDE_ARGUMENT_NAME = 'position_side';

    private readonly PositionServiceInterface $positionService;

    private function getPosition(): Position
    {
        $argName = self::POSITION_SIDE_ARGUMENT_NAME;
        $providedPositionSideValue = $this->paramFetcher->getStringArgument($argName);
        if (!$positionSide = Side::tryFrom($providedPositionSideValue)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $%s provided ("%s" given)', $argName, $providedPositionSideValue),
            );
        }

        if (!$position = $this->positionService->getPosition(Symbol::BTCUSDT, $positionSide)) {
            throw new RuntimeException(sprintf('Position on "%s" side not found', $positionSide->title()));
        }

        return $position;
    }

    private function configurePositionArgs(): static
    {
        $this->addArgument(self::POSITION_SIDE_ARGUMENT_NAME, InputArgument::REQUIRED, 'Position side (sell|buy)');

        return $this;
    }

    private function withPositionService(PositionServiceInterface $positionService): static
    {
        $this->positionService = $positionService;

        return $this;
    }
}
