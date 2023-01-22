<?php

declare(strict_types=1);

namespace App\Tests\Mock\Yandex;

use Symfony\Component\HttpClient\Response\MockResponse;

final class YandexGeocoderMockResponseFactory
{
    public static function found(): MockResponse
    {
        return new MockResponse(<<<JSON
{
  "response": {
    "GeoObjectCollection": {
      "metaDataProperty": {
        "GeocoderResponseMetaData": {
          "request": "Москва, Сумской проезд 10",
          "results": "1",
          "found": "1"
        }
      },
      "featureMember": [
        {
          "GeoObject": {
            "metaDataProperty": {
              "GeocoderMetaData": {
                "precision": "exact",
                "text": "Россия, Москва, Сумской проезд, 10",
                "kind": "house",
                "Address": {
                  "country_code": "RU",
                  "formatted": "Россия, Москва, Сумской проезд, 10",
                  "postal_code": "117208",
                  "Components": [
                    {
                      "kind": "country",
                      "name": "Россия"
                    },
                    {
                      "kind": "province",
                      "name": "Центральный федеральный округ"
                    },
                    {
                      "kind": "province",
                      "name": "Москва"
                    },
                    {
                      "kind": "locality",
                      "name": "Москва"
                    },
                    {
                      "kind": "street",
                      "name": "Сумской проезд"
                    },
                    {
                      "kind": "house",
                      "name": "10"
                    }
                  ]
                },
                "AddressDetails": {
                  "Country": {
                    "AddressLine": "Россия, Москва, Сумской проезд, 10",
                    "CountryNameCode": "RU",
                    "CountryName": "Россия",
                    "AdministrativeArea": {
                      "AdministrativeAreaName": "Москва",
                      "Locality": {
                        "LocalityName": "Москва",
                        "Thoroughfare": {
                          "ThoroughfareName": "Сумской проезд",
                          "Premise": {
                            "PremiseNumber": "10",
                            "PostalCode": {
                              "PostalCodeNumber": "117208"
                            }
                          }
                        }
                      }
                    }
                  }
                }
              }
            },
            "name": "Сумской проезд, 10",
            "description": "Москва, Россия",
            "boundedBy": {
              "Envelope": {
                "lowerCorner": "37.60487 55.632632",
                "upperCorner": "37.613081 55.637276"
              }
            },
            "Point": {
              "pos": "37.608975 55.634954"
            }
          }
        }
      ]
    }
  }
}
JSON, ['http_code' => 200]);
    }

    public static function notFound(): MockResponse
    {
        return new MockResponse(<<<JSON
{
  "response": {
    "GeoObjectCollection": {
      "metaDataProperty": {
        "GeocoderResponseMetaData": {
          "request": "Unknown address",
          "results": "1",
          "found": "0"
        }
      },
      "featureMember": []
    }
  }
}
JSON, ['http_code' => 200]);
    }

    public static function unauthorized(): MockResponse
    {
        return new MockResponse(<<<JSON
{
  "statusCode": 403,
  "error": "Forbidden",
  "message": "Invalid key"
}
JSON, ['http_code' => 403]);
    }
}
