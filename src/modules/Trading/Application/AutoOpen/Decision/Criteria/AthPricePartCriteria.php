<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;

final class AthPricePartCriteria extends AbstractOpenPositionCriteria
{
    public static function getAlias(): string
    {
        return 'price-percent-of-ath';
    }

    /**
     * @param array<string, Percent> $athPartThresholdOverrides
     */
    public function __construct(
        private array $athPartThresholdOverrides = []
    ) {
        foreach ($this->athPartThresholdOverrides as $key => $athPartOverride) {
            $side = Side::from($key);
            assert($athPartOverride instanceof Percent);
        }
    }

    public function addThresholdOverride(Side $side, Percent $override): self
    {
        $this->athPartThresholdOverrides[$side->value] = $override;

        return $this;
    }

    public function getAthThresholdPercentOverride(Side $side): ?Percent
    {
        return $this->athPartThresholdOverrides[$side->value] ?? null;
    }

    public function jsonSerialize(): mixed
    {
        return array_merge(parent::jsonSerialize(), [
            'athPartThresholdOverrides' => $this->athPartThresholdOverrides
        ]);
    }
}
