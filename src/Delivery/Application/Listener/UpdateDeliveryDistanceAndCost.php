<?php

declare(strict_types=1);

namespace App\Delivery\Application\Listener;

use App\Delivery\Application\Exception\DeliveryDestinationNotFound;
use App\Delivery\Application\Service\Geo\DistanceCalculatorInterface;
use App\Delivery\Application\Service\Geo\GeoObjectProvider;
use App\Delivery\Application\Service\Geo\GeoObjectProviderInterface;
use App\Delivery\Domain\Delivery;
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
        private readonly DistanceCalculatorInterface $distanceCalculator,
        private readonly GeoObjectProviderInterface $geoObjectProvider,
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

        $deliveryDistance = $this->getDeliveryDistance($delivery);
        $deliveryCost = $this->deliveryCostCalculator->calculate($deliveryDistance ?: 1, ...$this->prices); // min 1km

        $delivery->setDistance($deliveryDistance);
        $delivery->setCost($deliveryCost);

        $this->deliveryRepository->save($delivery);
    }

    private function getDeliveryDistance(Delivery $delivery): int
    {
        $depot = $this->geoObjectProvider->findGeoObject($this->depotAddress);
        $destination = $this->geoObjectProvider->findGeoObject($delivery->getAddress());

        if (!$destination) {
            throw DeliveryDestinationNotFound::forAddress($delivery->getAddress());
        }

        return $this->distanceCalculator->getDistanceBetween($depot, $destination);
    }
}
