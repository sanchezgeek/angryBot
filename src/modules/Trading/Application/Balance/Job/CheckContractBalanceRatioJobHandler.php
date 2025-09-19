<?php

declare(strict_types=1);

namespace App\Trading\Application\Balance\Job;

use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Settings\Application\Helper\SettingsHelper;
use App\Trading\Application\Settings\Balance\TradingBalanceSettings;
use App\Worker\AppContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckContractBalanceRatioJobHandler
{
    public function __invoke(CheckContractBalanceRatioJob $job): void
    {
        if (!AppContext::hasPermissionsToFundBalance()) {
            return;
        }
        $coin = $job->coin;

        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($coin);
        $contractBalanceAvailable = $contractBalance->available();

        $threshold = SettingsHelper::exact(TradingBalanceSettings::AvailableContractBalance_Threshold);
        if ($contractBalanceAvailable < $threshold) {
            return;
        }

        $contractRealBalance = $contractBalance->free();
        if ($contractRealBalance <= 0) {
            $contractRealBalance = $contractBalanceAvailable / 3;
        }

        $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($coin)->available();
        $wholeRealBalance = $contractRealBalance + $spotBalance;

        $maxRatio = SettingsHelper::exact(TradingBalanceSettings::RealContractBalance_Max_Ratio);
        $maxAllowedOnContractBalance = $wholeRealBalance * $maxRatio;

        if ($contractRealBalance > $maxAllowedOnContractBalance) {
            $diff = $contractRealBalance - $maxAllowedOnContractBalance;
            $transferAmount = min($contractBalanceAvailable - 1, $diff);
            $this->exchangeAccountService->interTransferFromContractToSpot($coin, $transferAmount);
        }
    }

    public function __construct(
        private ByBitExchangeAccountService $exchangeAccountService,
    ) {
    }
}
