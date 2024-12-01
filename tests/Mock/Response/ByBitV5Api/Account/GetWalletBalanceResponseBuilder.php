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

final class GetWalletBalanceResponseBuilder implements ResponseBuilderInterface
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
        'accountType' => 'UNIFIED',
        'totalEquity' => '17675.54658936',
        'accountIMRate' => '0.0785',
        'totalMarginBalance' => '17675.54658936',
        'totalInitialMargin' => '1388.08488494',
        'totalAvailableBalance' => '16287.46170442',
        'accountMMRate' => '0.0304',
        'totalPerpUPL' => '16172.74060582',
        'totalWalletBalance' => '1502.80598353',
        'accountLTV' => '0',
        'totalMaintenanceMargin' => '538.13305221',
        'coin' => [
            [
                'coin' => 'USDT',
                'availableToBorrow' => '',
                'bonus' => '0',
                'accruedInterest' => '0',
                'availableToWithdraw' => '1501.71873917',
                'totalOrderIM' => '0',
                'equity' => '17662.75875203',
                'totalPositionMM' => '537.74372576',
                'usdValue' => '17675.54658936',
                'unrealisedPnl' => '16161.04001286',
                'collateralSwitch' => true,
                'spotHedgingQty' => '0',
                'borrowAmount' => '0.000000000000000000',
                'totalPositionIM' => '1387.08063856',
                'walletBalance' => '1501.71873917',
                'cumRealisedPnl' => '-12466.15546267',
                'locked' => '0',
                'marginCollateral' => true,
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

    /**
     * @todo rename
     */
    public function withUnifiedBalance(Coin $coin, float $totalAmount, float $totalPositionIM): self
    {
        $item = self::COIN_BALANCE_ITEM;

        $item['accountType'] = AccountType::UNIFIED->value;
        $item['coin'][0]['coin'] = $coin->value;
        $item['coin'][0]['walletBalance'] = (string)$totalAmount;
        $item['coin'][0]['totalPositionIM'] = (string)$totalPositionIM;

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
