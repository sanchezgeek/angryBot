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

final class AllCoinsBalanceResponseBuilder implements ResponseBuilderInterface
{
    use MockResponseFactoryTrait;

    public const ROOT_BODY_ARRAY = [
        "retCode" => 0,
        "retMsg" => "OK",
        "result" => [
            'memberId' => '100500',
            'accountType' => 'FUND',
            'balance' => [],
        ],
        "retExtInfo" => [],
        "time" => 1672376496682
    ];

    public const COIN_BALANCE_ITEM = ['coin' => 'USDT', 'transferBalance' => '2527.475', 'walletBalance' => '2527.475', 'bonus' => ''];

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

    public function withAvailableFundBalance(CoinAmount $availableAmount): self
    {
        $item = self::COIN_BALANCE_ITEM;

        $item['coin'] = $availableAmount->coin()->value;
        $item['walletBalance'] = (string)$availableAmount->value();
        $item['transferBalance'] = (string)$availableAmount->value();

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

        $body['result']['balance'] = $this->listItems;

        return self::make($body);
    }
}
