<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Balance;

use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Trading\Contract\ContractBalanceProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

trait ContractBalanceBalanceMocker
{
    protected ContractBalanceProviderInterface|MockObject|null $contractBalanceProviderMock = null;

    protected function initializeContractBalanceProviderMock(): ContractBalanceProviderInterface|MockObject
    {
        $this->contractBalanceProviderMock = $this->createMock(ContractBalanceProviderInterface::class);
//        $contractBalanceProvider->method('getContractWalletBalance')->willReturn(new ContractBalance(Coin::USDT, 100, 100, 100, 100));

        self::setContractBalanceProviderInContainer($this->contractBalanceProviderMock);

        return $this->contractBalanceProviderMock;
    }

    public function getMockedContractBalanceProvider(): ContractBalanceProviderInterface|MockObject
    {
        if (!$this->contractBalanceProviderMock) {
            throw new RuntimeException('Initialize stub first with ->initializeTaProviderStub');
        }

        return $this->contractBalanceProviderMock;
    }

    public static function setContractBalanceProviderInContainer(ContractBalanceProviderInterface $provider): void
    {
        self::getContainer()->set(ContractBalanceProviderInterface::class, $provider);
    }

    public function mockContractBalance(ContractBalance $contractBalance): void
    {
        $this->contractBalanceProviderMock->method('getContractWalletBalance')->willReturn($contractBalance);
    }
}
