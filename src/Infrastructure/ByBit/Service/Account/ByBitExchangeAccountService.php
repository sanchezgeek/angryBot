<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Account;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\AbstractExchangeAccountService;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
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
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

use function is_array;
use function max;
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
                            $total = (float)$coinData['walletBalance'];
                            $available = (float)$coinData['availableToWithdraw'];

                            try {
                                $free = $this->calcFreeContractBalance($coin, $total, $available);
                            } catch (Throwable $e) {
                                OutputHelper::warning(sprintf('%s: "%s" when trying to calc free contract balance.', __FUNCTION__, $e->getMessage()));
                                $free = $available;
                            }

                            $walletBalance = new WalletBalance($accountType, $coin, $total, $available, (new CoinAmount($coin, $free))->value());
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
    private function calcFreeContractBalance(Coin $coin, float $total, float $available): float
    {
        // @todo | Temporary solution? (only if there is only BTCUSDT position is opened)
        $symbol = null;
        foreach (Symbol::cases() as $symbol) {
            if ($symbol->associatedCoin() === $coin) break;
        }

        // @todo | because of now positionService only works with linear category
        try {
            $positions = $this->positionService->getPositions($symbol);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'Unsupported symbol associated category') $positions = []; else throw $e;
        }

        if (!$positions) {
            return $total;
        }

        $hedge = $positions[0]->getHedge();
        if ($hedge && $hedge->isEquivalentHedge()) {
            return $available;
        }

        $positionForCalc = $hedge ? $hedge->mainPosition : $positions[0];
        $maintenanceMarginLiquidationDistance = $this->positionLiquidationCalculator->getMaintenanceMarginLiquidationDistance($positionForCalc);
        $availableFundsLiquidationDistance = $positionForCalc->liquidationDistance() - $maintenanceMarginLiquidationDistance;
        $fundsAvailableForLiquidation = $availableFundsLiquidationDistance * $positionForCalc->getNotCoveredSize();

        if ($hedge?->isProfitableHedge()) {
            $free = $fundsAvailableForLiquidation - $hedge->getSupportProfitOnMainEntryPrice();
            /**
             * Case when this is almost equivalent hedge
             * @todo | need some normal solution
             */
            if ($free < 0 && $positionForCalc->isLong() && $positionForCalc->liquidationPrice <= 0.00) {
                $free = max($free, $available);
            }
        } else {
            $free = $fundsAvailableForLiquidation;
        }

        # check is correct
        $liquidationRecalculated = $this->positionLiquidationCalculator->handle($positionForCalc, new CoinAmount($coin, $free))->estimatedLiquidationPrice();
        if (($diff = abs($positionForCalc->liquidationPrice - $liquidationRecalculated->value())) > 1) {
            OutputHelper::warning(sprintf('%s: recalculated liquidationPrice is not equals real one (diff: %s).', __FUNCTION__, $diff));
        }

        return $free;
//        $ticker = $ticker ?: $this->exchangeService->ticker($positionForCalc->symbol);
//        $priceDelta = $ticker->lastPrice->differenceWith($main->entryPrice);
//        $isMainPositionInLoss = $priceDelta->isLossFor($main->side);
//        if ($contractBalance->availableBalance === 0.0) {
//            $support = $hedge->supportPosition;
//            $free = ($main->positionBalance->value() - $main->initialMargin->value())
//                + ($support->initialMargin->value() - $support->positionBalance->value());
//
//            if ($isMainPositionInLoss) {
//                $notCoveredPartOrder = new ExchangeOrder($main->symbol, $notCoveredSize, $main->entryPrice);
//                $feeForCloseNotCovered = $this->orderCostCalculator->closeFee($notCoveredPartOrder, $main->leverage, $main->side)->value();
//                $feeForOpenNotCovered = $this->orderCostCalculator->openFee($notCoveredPartOrder)->value();
//
//                $free -= $feeForCloseNotCovered; // но comment сработал для ситуации, когда free всё таки больше 0 (sub#2  => delta   (real - calculated)   :   -0.300)
//                $free -= $feeForOpenNotCovered;
//            }
//        } else {
//            if ($isMainPositionInLoss) {
//                $loss = $notCoveredSize * $priceDelta->delta();
//                $free = $contractBalance->availableBalance + $loss;
//            } else {
//                $free = $contractBalance->availableBalance;
//            }
//        }
//
//        OutputHelper::print(sprintf('%s: %.5f', __FUNCTION__, $free));
//        return new CoinAmount($contractBalance->assetCoin, $free);
    }
}
