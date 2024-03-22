<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Account;

use App\Bot\Application\Service\Exchange\Account\AbstractExchangeAccountService;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\Exception\AccountCoinDataNotFound;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Helper\FloatHelper;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Infrastructure\ByBit\API\V5\Request\Coin\CoinInterTransfer;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;

use function is_array;
use function sprintf;
use function uuid_create;

final class ByBitExchangeAccountService extends AbstractExchangeAccountService
{
    use ByBitApiCallHandler;

    public function __construct(ByBitApiClientInterface $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * @throws AccountCoinDataNotFound
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     */
    public function getSpotWalletBalance(Coin $coin): WalletBalance
    {
        return $this->getWalletBalance(AccountType::SPOT, $coin);
    }

    /**
     * @throws AccountCoinDataNotFound
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     */
    public function getContractWalletBalance(Coin $coin): WalletBalance
    {
        return $this->getWalletBalance(AccountType::CONTRACT, $coin);
    }

    public function interTransferFromSpotToContract(Coin $coin, float $amount): void
    {
        $this->interTransfer($coin, AccountType::SPOT, AccountType::CONTRACT, FloatHelper::round($amount, 3));
    }

    public function interTransferFromContractToSpot(Coin $coin, float $amount): void
    {
        $this->interTransfer($coin, AccountType::CONTRACT, AccountType::SPOT, FloatHelper::round($amount, 3));
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
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
     * @throws AccountCoinDataNotFound
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
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
            throw new AccountCoinDataNotFound('ByBit', $accountType, $coin);
        }

        return $walletBalance;
    }
}
