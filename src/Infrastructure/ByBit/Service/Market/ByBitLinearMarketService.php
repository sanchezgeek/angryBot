<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Market;

use App\Bot\Application\Service\Exchange\Exchange\InstrumentInfoDto;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetInstrumentInfoRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Trading\Domain\Symbol\SymbolInterface;

final class ByBitLinearMarketService
{
    use ByBitApiCallHandler;

    private const AssetCategory ASSET_CATEGORY = AssetCategory::linear;

    public function __construct(
        ByBitApiClientInterface $apiClient,
    ) {
        $this->apiClient = $apiClient;
    }

    public function getInstrumentInfo(SymbolInterface|string $symbol): InstrumentInfoDto
    {
        $request = new GetInstrumentInfoRequest(self::ASSET_CATEGORY, $symbol);
        $data = $this->sendRequest($request)->data();

        return new InstrumentInfoDto(
            (float)$data['list'][0]['lotSizeFilter']['minOrderQty'],
            (float)$data['list'][0]['lotSizeFilter']['minNotionalValue'],
            (float)$data['list'][0]['leverageFilter']['minLeverage'],
            (float)$data['list'][0]['leverageFilter']['maxLeverage'],
            (float)$data['list'][0]['priceFilter']['tickSize'],
            (int)$data['list'][0]['priceScale'],
            $data['list'][0]['quoteCoin'],
            $data['list'][0]['contractType'],
        );
    }
}
