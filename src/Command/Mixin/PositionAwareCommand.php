<?php

namespace App\Command\Mixin;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;

use function sprintf;

trait PositionAwareCommand
{
    use SymbolAwareCommand;
    use ConsoleInputAwareCommand;

    private const POSITION_SIDE_ARGUMENT_NAME = 'position_side';

    private readonly PositionServiceInterface $positionService;

    private function getPosition(bool $throwException = true): ?Position
    {
        $symbol = $this->getSymbol();
        $side = $this->getPositionSide();

        if (!$position = $this->positionService->getPosition($symbol, $side)) {
            if ($throwException) {
                throw new RuntimeException(sprintf('"%s" "%s" position not found', $symbol->value, $side->title()));
            }

            return null;
        }

        return $position;
    }

    private function getPositionSide(): Side
    {
        $argName = self::POSITION_SIDE_ARGUMENT_NAME;
        $providedPositionSideValue = $this->paramFetcher->getStringArgument($argName);
        if (!$positionSide = Side::tryFrom($providedPositionSideValue)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $%s provided ("%s" given)', $argName, $providedPositionSideValue),
            );
        }

        return $positionSide;
    }

    private function configurePositionArgs(int $mode = InputArgument::REQUIRED): static
    {
        if (!$this->isSymbolArgsConfigured()) {
            $this->configureSymbolArgs();
        }

        return $this->addArgument(self::POSITION_SIDE_ARGUMENT_NAME, $mode, 'Position side (sell|buy)');
    }

    private function withPositionService(PositionServiceInterface $positionService): static
    {
        $this->positionService = $positionService;

        return $this;
    }
}
