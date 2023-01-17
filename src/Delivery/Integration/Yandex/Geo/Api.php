<?php

declare(strict_types=1);

namespace App\Delivery\Integration\Yandex\Geo;

use App\Delivery\Integration\Yandex\Geo\Exception\CurlError;
use App\Delivery\Integration\Yandex\Geo\Exception\ServerError;

/**
 * @see http://api.yandex.ru/maps/doc/geocoder/desc/concepts/About.xml
 */
final class Api
{
    const LANG_RU = 'ru-RU';

    protected string $version = '1.x';

    protected array $filters = [];

    protected ?Result $result = null;

    public function __construct(string $yandexApiKey, ?string $version = null)
    {
        $this->version = $version ?: $this->version;

        $this->reset();

        $this->setToken($yandexApiKey);
    }

    /**
     * @throws ServerError
     * @throws Exception
     * @throws CurlError
     */
    public function load(): self
    {
        $apiUrl = sprintf('https://geocode-maps.yandex.ru/%s/?%s', $this->version, http_build_query($this->filters));

        $curl = curl_init($apiUrl);
        $options = [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPGET => 1,
            CURLOPT_FOLLOWLOCATION => 1,
        ];
        curl_setopt_array($curl, $options);

        $data = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (curl_errno($curl)) {
            curl_close($curl);
            throw new CurlError(curl_error($curl));
        }
        curl_close($curl);

        if (in_array($code, [500, 502])) {
            $msg = strip_tags($data);
            throw new ServerError(trim($msg), $code);
        }

        if (!($data = json_decode($data, true))) {
            $msg = sprintf('Can\'t load data by url: %s', $apiUrl);
            throw new Exception($msg);
        }

        $this->result = new Result($data);

        return $this;
    }

    public function getResult(): Result
    {
        return $this->result;
    }

    public function reset(): self
    {
        $this->filters = ['format' => 'json'];

        $this
            ->setLang(self::LANG_RU)
            ->setOffset(0)
            ->setLimit(10);

        $this->result = null;

        return $this;
    }

    public function setQuery(string $query): self
    {
        $this->filters['geocode'] = $query;

        return $this;
    }

    public function setLimit(int $limit): self
    {
        $this->filters['results'] = $limit;

        return $this;
    }

    public function setOffset(int $offset): self
    {
        $this->filters['skip'] = $offset;

        return $this;
    }

    public function setLang(string $lang): self
    {
        $this->filters['lang'] = $lang;

        return $this;
    }

    public function setToken(string $token): self
    {
        $this->filters['apikey'] = $token;

        return $this;
    }
}
