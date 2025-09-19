<?php

declare(strict_types=1);

namespace App\Trading\Domain\Assertion;

use App\Domain\Position\ValueObject\Side;
use App\Helper\FloatHelper;
use App\TechnicalAnalysis\Domain\Dto\Ath\PricePartOfAth;
use App\TechnicalAnalysis\Domain\Dto\Ath\PricePartOfAthDesc;

final class IsPriceChangeSignificantAssertion
{
    public function assert(Side $positionSide): bool
    {
        /**
         * Чем меньше partOfATH, тем больше должно быть изменение цены
         * Хотя опять же если там funding = 0.01234, то и порог нужно понижать
         * Но это уже не про IsPriceChangeSignificantAssertion, т.е. funding - входящий параметр?
         * Логично ли это? Логика такая:
         * Предусловия:
         * 1) был сильный импельс вверх
         * 2) funding = 0.01234
         * 3) partOfATH = 0.3
         *
         * То есть трейдеры засели в лонги и дальнейшее движение цены нецелесообразно.
         * Другой фактор - ценность актива. Даже если и будет коррекция, то она может быть небольшой, после чего движение продолжится
         * ************ НАВЕРНОЕ в тестах funding надо дописать случай, когда funding резко вырос на два периода назад
         *      (начало роста, подхватили лонгистов) и в этом случае FundingIsAppropriateHandler не должен делать выводов о том, что надо заходить в шорт на всё
         *
         * Как сочетать эти факторы?
         *
         * Если проверку других факторов не делать здесь?
         * То есть вход:
         * 1) PriceChangeInfo
         * 2) price.partOfATH
         *
         * Метод (для LONG и SHORT) настраивает $atrBaseMultiplierOverride
         * Получает $significantPriceChangePercent
         * Делает сравнение
         */

    }

    public function getAtrBaseMultiplier(Side $positionSideBias, PricePartOfAth $partOfATH): float
    {
        $minForOverATH = 1;

        if ($partOfATH->desc === PricePartOfAthDesc::MovedOverLow && $positionSideBias->isLong()) {
            return $minForOverATH;
        }

        if ($partOfATH->desc === PricePartOfAthDesc::MovedOverHigh && $positionSideBias->isShort()) {
            return $minForOverATH;
        }

        $minAtrBaseMultiplier = 2;
        $maxAtrBaseMultiplier = 10;

        $part = $partOfATH->percent->part();


        $k = $positionSideBias->isShort() ? 1 - $part : $part;

        if ($partOfATH->desc === PricePartOfAthDesc::MovedOverLow && $positionSideBias->isShort()) {
            $k = 1 + (1 - $k);
        }

        $atrBaseMultiplier = $maxAtrBaseMultiplier * $k;

        if ($atrBaseMultiplier < $minAtrBaseMultiplier) {
            $atrBaseMultiplier = $minAtrBaseMultiplier;
        }

//        if ($atrBaseMultiplier > $maxAtrBaseMultiplier) {
//            $atrBaseMultiplier = $maxAtrBaseMultiplier;
//        }

        return FloatHelper::round($atrBaseMultiplier);
    }
}
