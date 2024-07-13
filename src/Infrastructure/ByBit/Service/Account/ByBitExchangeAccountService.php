<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Account;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\AbstractExchangeAccountService;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\PermissionDeniedException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Infrastructure\ByBit\API\V5\Request\Coin\CoinInterTransfer;
use App\Infrastructure\ByBit\API\V5\Request\Coin\CoinUniversalTransferRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use Psr\Log\LoggerInterface;

use function is_array;
use function sprintf;
use function uuid_create;

final class ByBitExchangeAccountService extends AbstractExchangeAccountService
{
    use ByBitApiCallHandler;

    public function __construct(
        ByBitApiClientInterface $apiClient,
        private readonly LoggerInterface $appErrorLogger,
        private readonly OrderCostCalculator $orderCostCalculator,
        private readonly CalcPositionLiquidationPriceHandler $positionLiquidationCalculator,
        private readonly PositionServiceInterface $positionService
    ) {
        $this->apiClient = $apiClient;
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    public function getSpotWalletBalance(Coin $coin): WalletBalance
    {
        return $this->getWalletBalance(AccountType::SPOT, $coin);
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    public function getContractWalletBalance(Coin $coin): WalletBalance
    {
        return $this->getWalletBalance(AccountType::CONTRACT, $coin);
    }

    public function universalTransfer(
        Coin $coin,
        float $amount,
        AccountType $fromAccountType,
        AccountType $toAccountType,
        string $fromMemberUid,
        string $toMemberUid,
    ): void {
        $request = new CoinUniversalTransferRequest(
            new CoinAmount($coin, $amount),
            $fromAccountType,
            $toAccountType,
            $fromMemberUid,
            $toMemberUid,
            $transferId = uuid_create(),
        );

        $result = $this->sendRequest($request);

        $data = $result->data();

        $actualId = $data['transferId'];
        if ($actualId !== $transferId) {
            throw BadApiResponseException::common($request, sprintf('got another `transferId` (%s insteadof %s)', $actualId, $transferId), __METHOD__);
        }
    }

    /**
     * @throws UnexpectedApiErrorException
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    public function interTransferFromSpotToContract(Coin $coin, float $amount): void
    {
        $this->interTransfer($coin, AccountType::SPOT, AccountType::CONTRACT, FloatHelper::round($amount, 8));
    }

    /**
     * @throws UnexpectedApiErrorException
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    public function interTransferFromContractToSpot(Coin $coin, float $amount): void
    {
        $this->interTransfer($coin, AccountType::CONTRACT, AccountType::SPOT, FloatHelper::round($amount, 8));
    }

    /**
     * @throws UnexpectedApiErrorException
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    public function interTransferFromFundingToSpot(Coin $coin, float $amount): void
    {
        $this->interTransfer($coin, AccountType::FUNDING, AccountType::SPOT, FloatHelper::round($amount, 8));
    }

    /**
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws ApiRateLimitReached
     * @throws PermissionDeniedException
     */
    public function interTransferFromSpotToFunding(Coin $coin, float $amount): void
    {
        $this->interTransfer($coin, AccountType::SPOT, AccountType::FUNDING, FloatHelper::round($amount, 8));
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    private function interTransfer(Coin $coin, AccountType $from, AccountType $to, float $amount): void
    {
        $request = new CoinInterTransfer(new CoinAmount($coin, $amount), $from, $to, $transferId = uuid_create());

        $result = $this->sendRequest($request);

        $data = $result->data();

        $actualId = $data['transferId'];
        if ($actualId !== $transferId) {
            throw BadApiResponseException::common($request, sprintf('got another `transferId` (%s insteadof %s)', $actualId, $transferId), __METHOD__);
        }
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    private function getWalletBalance(AccountType $accountType, Coin $coin): WalletBalance
    {
        $request = new GetWalletBalanceRequest($accountType, $coin);

        $data = $this->sendRequest($request)->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $walletBalance = null;
        foreach ($list as $item) {
            if ($item['accountType'] === $accountType->value) {
                foreach ($item['coin'] as $coinData) {
                    if ($coinData['coin'] === $coin->value) {
                        if ($accountType === AccountType::SPOT) {
                            $walletBalance = new WalletBalance($accountType, $coin, (float)$coinData['walletBalance'], (float)$coinData['free']);
                        } elseif ($accountType === AccountType::CONTRACT) {
                            $walletBalance = new WalletBalance($accountType, $coin, (float)$coinData['walletBalance'], (float)$coinData['availableToWithdraw']);
                        }
                    }
                }
            }
        }

        if (!$walletBalance) {
            $this->appErrorLogger->critical(
                sprintf('[ByBit] %s %s coin data not found', $accountType->value, $coin->value),
                ['file' => __FILE__, 'line' => __LINE__]
            );

            return new WalletBalance($accountType, $coin, 0, 0);
        }

        return $walletBalance;
    }

    /**
     * @todo tests
     */
    public function calcFreeContractBalance(Coin $coin): CoinAmount
    {
        $contractBalance = $this->getContractWalletBalance($coin);

        // @todo | Temporary solution? (only if there is only BTCUSDT position is opened)
        $symbol = null;
        foreach (Symbol::cases() as $symbol) {
            if ($symbol->associatedCoin() === $coin) break;
        }

        $positions = $this->positionService->getPositions($symbol);
        if (!($hedge = $positions[0]->getHedge())) {
            // @todo | check value is actual (without hedge)
            $result = $contractBalance->total->sub($positions[0]->initialMargin)->value();
        } else {
            $main = $hedge->mainPosition;
//            if ($main->isPositionInProfit($ticker->lastPrice)) $result = $contractBalance->availableBalance;
            $totalLiquidationDistance = $main->liquidationDistance();

            $maintenanceMarginLiquidationDistance = $this->positionLiquidationCalculator->getMaintenanceMarginLiquidationDistance($main);
            $availableFundsLiquidationDistance = $totalLiquidationDistance - $maintenanceMarginLiquidationDistance;
            $fundsAvailableForLiquidation = $availableFundsLiquidationDistance * $main->getNotCoveredSize();

            $result = $hedge->isProfitableHedge()
                ? $fundsAvailableForLiquidation - $hedge->getSupportProfitOnMainEntryPrice()
                : $fundsAvailableForLiquidation
            ;

            // @todo | take case when `$main->isLong() && $liquidationDistance >= $main->entryPrice)` into account
        }
        OutputHelper::print(sprintf('%s: %.5f', __FUNCTION__, $result));
        $freeContractBalance = new CoinAmount($contractBalance->assetCoin, $result);

        # check is correct
        $positionToReCalcLiquidation = $hedge ? $hedge->mainPosition : $positions[0];
        $liquidationRecalculated = $this->positionLiquidationCalculator->handle($positionToReCalcLiquidation, $freeContractBalance)->estimatedLiquidationPrice();
        if ($positionToReCalcLiquidation->liquidationPrice !== $liquidationRecalculated->value()) {
            OutputHelper::warning(sprintf('%s: recalculated liquidationPrice is not equals real one.', __FUNCTION__));
        }

        return $freeContractBalance;

        $ticker = $ticker ?: $this->exchangeService->ticker($positionToReCalcLiquidation->symbol);
        $priceDelta = $ticker->lastPrice->differenceWith($main->entryPrice);
        $isMainPositionInLoss = $priceDelta->isLossFor($main->side);


        if ($contractBalance->availableBalance === 0.0) {
            $support = $hedge->supportPosition;
            $result = ($main->positionBalance->value() - $main->initialMargin->value())
                + ($support->initialMargin->value() - $support->positionBalance->value());

            if ($isMainPositionInLoss) {
                $notCoveredPartOrder = new ExchangeOrder($main->symbol, $notCoveredSize, $main->entryPrice);
                $feeForCloseNotCovered = $this->orderCostCalculator->closeFee($notCoveredPartOrder, $main->leverage, $main->side)->value();
                $feeForOpenNotCovered = $this->orderCostCalculator->openFee($notCoveredPartOrder)->value();

                $result -= $feeForCloseNotCovered; // но comment сработал для ситуации, когда free всё таки больше 0 (sub#2  => delta   (real - calculated)   :   -0.300)
                $result -= $feeForOpenNotCovered;
            }
        } else {
            if ($isMainPositionInLoss) {
                $loss = $notCoveredSize * $priceDelta->delta();
                $result = $contractBalance->availableBalance + $loss;
            } else {
                $result = $contractBalance->availableBalance;
            }
        }

        OutputHelper::print(sprintf('%s: %.5f', __FUNCTION__, $result));
        return new CoinAmount($contractBalance->assetCoin, $result);
    }
}
