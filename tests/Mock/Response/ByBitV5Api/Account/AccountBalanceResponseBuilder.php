<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBitV5Api\Account;

use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Tests\Mock\Response\MockResponseFactoryTrait;
use App\Tests\Mock\Response\ResponseBuilderInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace_recursive;

final class AccountBalanceResponseBuilder implements ResponseBuilderInterface
{
    use MockResponseFactoryTrait;

    public const ROOT_BODY_ARRAY = [
        "retCode" => 0,
        "retMsg" => "OK",
        "result" => [
            "list" => []
        ],
        "retExtInfo" => [],
        "time" => 1672376496682
    ];

    public const COIN_BALANCE_ITEM = [
        "accountType" => "SPOT",
        "accountIMRate" => "",
        "accountMMRate" => "",
        "accountLTV" => "",
        "totalEquity" => "",
        "totalWalletBalance" => "",
        "totalMarginBalance" => "",
        "totalAvailableBalance" => "",
        "totalPerpUPL" => "",
        "totalInitialMargin" => "",
        "totalMaintenanceMargin" => "",
        "coin" => [
            [
                "coin" => "USDT",
                "equity" => "",
                "usdValue" => "",
                "walletBalance" => "2.8017489995",
                "free" => "2.8017489995",
                "locked" => "0",
                "availableToWithdraw" => "",
                "availableToBorrow" => "",
                "borrowAmount" => "",
                "accruedInterest" => "",
                "totalOrderIM" => "",
                "totalPositionIM" => "",
                "totalPositionMM" => "",
                "unrealisedPnl" => "",
                "cumRealisedPnl" => "",
            ],
        ],
    ];

    private array $listItems = [];

    private function __construct(private readonly ?ByBitV5ApiError $error)
    {
    }

    public static function ok(): self
    {
        return new self(null);
    }

    public static function error(ByBitV5ApiError $error): self
    {
        return new self($error);
    }

    public function withAvailableSpotBalance(AccountType $accountType, CoinAmount $availableAmount): self
    {
        $item = self::COIN_BALANCE_ITEM;

        $item['accountType'] = $accountType->value;
        $item['coin'][0]['coin'] = $availableAmount->coin()->value;
        $item['coin'][0]['walletBalance'] = (string)$availableAmount->value();
        $item['coin'][0]['free'] = (string)$availableAmount->value();

        $this->listItems[] = $item;

        return $this;
    }

    public function withContractBalance(AccountType $accountType, Coin $coin, float $totalAmount, float $availableAmount): self
    {
        $item = self::COIN_BALANCE_ITEM;

        $item['accountType'] = $accountType->value;
        $item['coin'][0]['coin'] = $coin->value;
        $item['coin'][0]['walletBalance'] = (string)$totalAmount;
        $item['coin'][0]['availableToWithdraw'] = (string)$availableAmount;

        $this->listItems[] = $item;

        return $this;
    }

    public function build(): MockResponse
    {
        $body = self::ROOT_BODY_ARRAY;

        if ($this->error) {
            $body = array_replace_recursive($body, [
                'retCode' => $this->error->code(),
                'retMsg' => $this->error->msg(),
            ]);

            return self::make($body);
        }

        $body['result']['list'] = $this->listItems;

        return self::make($body);
    }
}
