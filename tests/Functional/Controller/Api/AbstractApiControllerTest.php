<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Helper\Json;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AbstractApiControllerTest extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        parent::setUp();
    }

    protected function checkResponseCodeAndContent(int $code, array $expectedContent): void
    {
        self::assertResponseStatusCodeSame($code);
        self::assertResponseFormatSame('json');

        $actualContent = Json::decode($this->client->getResponse()->getContent());

        self::assertEqualsCanonicalizing(
            $expectedContent,
            $actualContent,
            Json::encode([$expectedContent, $actualContent])
        );
    }
}
