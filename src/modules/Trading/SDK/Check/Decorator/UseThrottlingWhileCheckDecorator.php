<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Decorator;

use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Exception\TooManyTriesForCheck;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class UseThrottlingWhileCheckDecorator implements TradingCheckInterface
{
    public function __construct(
        private TradingCheckInterface $decorated,
        private RateLimiterFactory $limiterFactory,
    ) {
    }

    public function supports(CheckOrderDto $orderDto, TradingCheckContext $context): bool
    {
        // @todo | performance | also use?
        return $this->decorated->supports($orderDto, $context);
    }

    public function check(CheckOrderDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        if (
            !$context->withoutThrottling
            && ($identifier = $orderDto->orderIdentifier())
            && !$this->limiterFactory->create($identifier)->consume()->isAccepted()
        ) {
            throw new TooManyTriesForCheck();
        }

        return $this->decorated->check($orderDto, $context);
    }
}
