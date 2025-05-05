<?php

namespace App\Connection\Application\Messenger\Job;

use App\Connection\Application\Settings\ConnectionSettings;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final readonly class CheckConnectionHandler
{
    public function __invoke(CheckConnection $dto): void
    {
        if ($this->settings->get(ConnectionSettings::CheckConnectionEnabled) !== true) {
            return;
        }

        try {
            $this->httpClient->request(Request::METHOD_GET, 'https://mail.ru');
        } catch (TransportExceptionInterface) {
            return;
        }

        $this->appErrorLogger->error('conn');
    }

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $appErrorLogger,
        private AppSettingsProviderInterface $settings
    ) {
    }
}
