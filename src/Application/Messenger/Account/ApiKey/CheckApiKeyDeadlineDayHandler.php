<?php

declare(strict_types=1);

namespace App\Application\Messenger\Account\ApiKey;

use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final class CheckApiKeyDeadlineDayHandler
{
    const LIMIT = 5;

    public function __invoke(CheckApiKeyDeadlineDay $message): void
    {
        if (!$this->apiKeyDeadlineDayThrottlingLimiter->create()->consume()->isAccepted()) {
            return;
        }

        $info = $this->exchangeAccountService->getApiKeyInfo();
        $daysLeft = (int)$info['deadlineDay'];
        if ($daysLeft < self::LIMIT) {
            $this->appErrorLogger->error(
                sprintf('%d days left before ApiKey expired. To refresh use `./bin/console acc:api-key:refresh`', $daysLeft)
            );
        }
    }

    public function __construct(
        private readonly ByBitExchangeAccountService $exchangeAccountService,
        private readonly LoggerInterface $appErrorLogger,
        private readonly RateLimiterFactory $apiKeyDeadlineDayThrottlingLimiter,
    ) {
    }
}
