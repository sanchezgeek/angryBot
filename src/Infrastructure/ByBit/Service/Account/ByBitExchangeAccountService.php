<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Account;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\AbstractExchangeAccountService;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\PermissionDeniedException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Account\CreateSubAccountApiKeyRequest;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetApiKeyInfoRequest;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Infrastructure\ByBit\API\V5\Request\Account\ModifyMasterApiKeyRequest;
use App\Infrastructure\ByBit\API\V5\Request\Account\ModifySubAccApiKeyRequest;
use App\Infrastructure\ByBit\API\V5\Request\Asset\Balance\GetAllCoinsBalanceRequest;
use App\Infrastructure\ByBit\API\V5\Request\Asset\Transfer\CoinInterTransferRequest;
use App\Infrastructure\ByBit\API\V5\Request\Asset\Transfer\CoinUniversalTransferRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Worker\AppContext;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

use function abs;
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
        private readonly PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
    ) {
        $this->apiClient = $apiClient;
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    public function getSpotWalletBalance(Coin $coin, bool $suppressUTAWarning = false): SpotBalance
    {
        $request = new GetAllCoinsBalanceRequest($accountType = AccountType::FUNDING, $coin);

        $data = $this->sendRequest($request)->data();
        if (!is_array($list = $data['balance'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $balance = null;
        foreach ($list as $item) {
            if ($item['coin'] === $coin->value) {
                $balance = new SpotBalance($coin, (float)$item['walletBalance'], (float)$item['transferBalance']);
            }
        }

        if (!$balance) {
            $this->appErrorLogger->critical(sprintf('[ByBit] %s %s coin data not found', $accountType->value, $coin->value), ['file' => __FILE__, 'line' => __LINE__]);
            return new SpotBalance($coin, 0, 0);
        }

        return $balance;
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    public function getContractWalletBalance(Coin $coin): ContractBalance
    {
        $request = new GetWalletBalanceRequest($accountType = AccountType::UNIFIED, $coin);

        $byBitApiCallResult = $this->sendRequest($request);
        $data = $byBitApiCallResult->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $balance = null;
        foreach ($list as $item) {
            if ($item['accountType'] === $accountType->value) {
                foreach ($item['coin'] as $coinData) {
                    if ($coinData['coin'] === $coin->value) {
                        $totalPositionIM = (float)$coinData['totalPositionIM'];

                        $total = (float)$coinData['walletBalance'];
                        $free = $total - $totalPositionIM;
                        $availableForTrade = $coinData['unrealisedPnl'] + $free;

                        try {
                            [$available, $freeForLiq] = $this->calcFreeContractBalance($coin, $total, $free);
                        } catch (Throwable $e) {
                            $message = sprintf('%s: "%s" when trying to calc free UTA balance.', __FUNCTION__, $e->getMessage());
                            $this->appErrorLogger->critical($message, ['file' => __FILE__, 'line' => __LINE__]);
                            OutputHelper::warning($message);

                            $available = $freeForLiq = $free;
                        }

                        $balance = new ContractBalance($coin, $total, $available, $free, $freeForLiq, $availableForTrade);
                    }
                }
            }
        }

        if (!$balance) {
            $this->appErrorLogger->critical(sprintf('[ByBit] %s %s coin data not found', $accountType->value, $coin->value), ['file' => __FILE__, 'line' => __LINE__]);
            return new ContractBalance($coin, 0, 0, 0, 0, 0);
        }

        return $balance;
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
        $this->interTransfer($coin, AccountType::FUNDING, AccountType::UNIFIED, $amount);
    }

    /**
     * @throws UnexpectedApiErrorException
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    public function interTransferFromContractToSpot(Coin $coin, float $amount): void
    {
        $this->interTransfer($coin, AccountType::UNIFIED, AccountType::FUNDING, $amount);
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     */
    private function interTransfer(Coin $coin, AccountType $from, AccountType $to, float $amount): void
    {
        $amount = FloatHelper::round($amount, $coin->coinCostPrecision());

        $request = self::interTransferFactory($coin, $from, $to, $amount, $transferId = uuid_create());
        $result = $this->sendRequest($request);

        $data = $result->data();

        $actualId = $data['transferId'];
        if ($request->transferId !== null && $actualId !== $transferId) {
            throw BadApiResponseException::common($request, sprintf('got another `transferId` (%s insteadof %s)', $actualId, $transferId), __METHOD__);
        }
    }

    private static function interTransferFactory(Coin $coin, AccountType $from, AccountType $to, float $amount, string $transferId): CoinInterTransferRequest
    {
        if (AppContext::isTest()) {
            return CoinInterTransferRequest::test(new CoinAmount($coin, $amount), $from, $to);
        }

        return CoinInterTransferRequest::real(new CoinAmount($coin, $amount), $from, $to, $transferId);
    }

    /**
     * @todo tests
     */
    private function calcFreeContractBalance(Coin $coin, float $total, float $free): array
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
            return [$total, $total];
        }

        $hedge = $positions[0]->getHedge();
        if ($hedge && $hedge->isEquivalentHedge()) {
            return [$free, $free];
        }

        $positionForCalc = $hedge ? $hedge->mainPosition : $positions[0];
        $maintenanceMarginLiquidationDistance = CalcPositionLiquidationPriceHandler::getMaintenanceMarginLiquidationDistance($positionForCalc);
        $availableFundsLiquidationDistance = $positionForCalc->liquidationDistance() - $maintenanceMarginLiquidationDistance;
        $notCoveredSize = $positionForCalc->getNotCoveredSize();
        $fundsAvailableForLiquidation = $availableFundsLiquidationDistance * $notCoveredSize;

        if ($hedge?->isProfitableHedge()) {
            $fundsAvailableForLiquidation = $fundsAvailableForLiquidation - $hedge->getSupportProfitOnMainEntryPrice();
        }

        # check is correct
        $this->checkCalculatedFundsForLiquidation($positionForCalc, $fundsAvailableForLiquidation);

        // what if there is network problems?
        $ticker = $this->exchangeService->ticker($symbol);

        $priceDelta = $ticker->lastPrice->differenceWith($positionForCalc->entryPrice());
        $isMainPositionInLoss = $priceDelta->isLossFor($positionForCalc->side);

        $available = $free;
        if ($isMainPositionInLoss) {
            $loss = $notCoveredSize * $priceDelta->absDelta();
            $available -= $loss;
        }

        // @todo + realAvailable?
        return [max($available, 0), $fundsAvailableForLiquidation];
    }

    private function checkCalculatedFundsForLiquidation(Position $position, float $fundsAvailableForLiquidation): void
    {
        if ($position->isShort() && !$position->liquidationPrice) { # skip if this is short without liquidation
            return;
        }

        $fundsAvailableForLiquidation = new CoinAmount($position->symbol->associatedCoin(), $fundsAvailableForLiquidation);

        $liquidationRecalculated = $this->positionLiquidationCalculator->handle($position, $fundsAvailableForLiquidation)->estimatedLiquidationPrice()->value();
        if (($diff = abs($position->liquidationPrice - $liquidationRecalculated)) > 1) {
            $msg = sprintf('ByBitExchangeAccountService::calcFreeContractBalance: recalculated liquidationPrice is not equals real one (diff: %s).', $diff);
//            $this->appErrorLogger->critical($msg, ['file' => __FILE__, 'line' => __LINE__]);
            OutputHelper::warning($msg);
        }
    }

    public function createSubAccountApiKey(int $uid, string $note): void
    {
        $request = new CreateSubAccountApiKeyRequest($uid, $note);
        $result = $this->sendRequest($request);

        var_dump($result->data());
    }

    public function getApiKeyInfo(): array
    {
        $result = $this->sendRequest(new GetApiKeyInfoRequest());

        return $result->data();
    }

    public function refreshApiKey(?string $subAccApiKey = null): array
    {
        if ($subAccApiKey) {
            $request = ModifySubAccApiKeyRequest::justRefresh($subAccApiKey);
        } elseif (AppContext::isMasterAccount()) {
            $request = ModifyMasterApiKeyRequest::justRefresh();
        } else {
            $request = ModifySubAccApiKeyRequest::justRefresh();
        }

        $result = $this->sendRequest($request);

        return $result->data();
    }
}
