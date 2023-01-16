<?php

declare(strict_types=1);

namespace App\Delivery\Application\Listener;

use App\Delivery\Domain\Event\DeliveryAddressChanged;
use App\Delivery\Application\Service\DeliveryCost\DeliveryCostCalculator;
use App\Delivery\Application\Service\DeliveryCost\DeliveryPriceRange;
use App\Delivery\Application\Service\Geo\DistanceCalculator;
use App\Delivery\Domain\DeliveryRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class UpdateDeliveryDistanceAndCost
{
    private readonly array $prices;

    public function __construct(
        private readonly DeliveryRepository $deliveryRepository,
        private readonly DistanceCalculator $distanceCalculator,
        private readonly DeliveryCostCalculator $deliveryCostCalculator,
        private readonly string $depotAddress,
    ) {
        $this->prices = [
            new DeliveryPriceRange(0, 10, 50),
            new DeliveryPriceRange(10, 25, 70),
            new DeliveryPriceRange(25, null, 80),
        ];
    }

    public function __invoke(DeliveryAddressChanged $event): void
    {
        $delivery = $this->deliveryRepository->find($event->deliveryId);

        // 1km if result distance less than 1km
        $distance = $this->distanceCalculator->getDistanceBetween($this->depotAddress, $delivery->getAddress()) ?: 1;
        $cost = $this->deliveryCostCalculator->calculate($distance, ...$this->prices);

        $delivery->setDistance($distance);
        $delivery->setCost($cost);

        $this->deliveryRepository->save($delivery);
    }
}
