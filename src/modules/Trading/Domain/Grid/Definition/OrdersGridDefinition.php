<?php

declare(strict_types=1);

namespace App\Trading\Domain\Grid\Definition;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use InvalidArgumentException;

final readonly class OrdersGridDefinition
{
    public function __construct(
        // @todo | PnlRange?
        public PriceRange $priceRange,
        public Percent $definedPercent,
        public int $ordersCount,
        public array $contextsDefs = []
    ) {
    }

    public static function create(
        string $definition,
        SymbolPrice $priceToRelate,
        Side $positionSide,
        Symbol $symbol
    ): self {
        $equivRangePattern = '/^\d+%\|\d+%(?:\|\d+)?(?:\|[,\w]+)?$/';
        $accurateRangePattern = '/^[-\d]+%\.\.[-\d]+%\|\d+%(?:\|\d+)?(?:\|[,\w]+)?$/';

        $isEquivRange = preg_match($equivRangePattern, $definition);
        $isAccurateRange = preg_match($accurateRangePattern, $definition);

        if (!$isEquivRange && !$isAccurateRange) {
            throw new InvalidArgumentException(
                sprintf('Invalid definition "%s" ("%s" or "%s" expected)', $definition, $equivRangePattern, $accurateRangePattern),
            );
        }

        $parts = explode('|', $definition);
        if ($isEquivRange) {
            $rangePnl = Percent::string($parts[0]);

            $fromPnl = -$rangePnl->value();
            $toPnl = $rangePnl->value();
        } else {
            $rangeDef = explode('..', $parts[0]);

            $fromPnl = (float)$rangeDef[0];
            $toPnl = (float)$rangeDef[1];
        }

        $from = PnlHelper::targetPriceByPnlPercent($priceToRelate, $fromPnl, $positionSide);
        $to = PnlHelper::targetPriceByPnlPercent($priceToRelate, $toPnl, $positionSide);

        $priceRange = PriceRange::create($from, $to, $symbol);
        $ordersCount = (int)($parts[2] ?? 1);
        $sizePart = Percent::string($parts[1]);

        $contextsDefs = $parts[3] ?? '';

        return new self($priceRange, $sizePart, $ordersCount, $contextsDefs ? explode(',', $contextsDefs) : []);
    }
}
