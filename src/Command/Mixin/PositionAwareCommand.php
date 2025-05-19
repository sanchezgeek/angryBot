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
    use ConsoleInputAwareCommand;
    use SymbolAwareCommand;

    private const POSITION_SIDE_ARGUMENT_NAME = 'position_side';

    private readonly PositionServiceInterface $positionService;

    protected function getPosition(bool $throwException = true): ?Position
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

    protected function getPositionSide(bool $required = true): ?Side
    {
        $argName = self::POSITION_SIDE_ARGUMENT_NAME;
        $providedPositionSideValue = $this->paramFetcher->getStringArgument($argName);
        if (!($positionSide = Side::tryFrom($providedPositionSideValue)) && $required) {
            throw new InvalidArgumentException(
                sprintf('Invalid $%s provided ("%s" given)', $argName, $providedPositionSideValue),
            );
        }

        return $positionSide;
    }

    public function configurePositionArgs(int $mode = InputArgument::REQUIRED): static
    {
        if (!$this->isSymbolArgsConfigured()) {
            $this->configureSymbolArgs();
        }

        return $this->addArgument(self::POSITION_SIDE_ARGUMENT_NAME, $mode, 'Position side (sell|buy)');
    }

    protected function withPositionService(PositionServiceInterface $positionService): static
    {
        $this->positionService = $positionService;

        return $this;
    }
}
