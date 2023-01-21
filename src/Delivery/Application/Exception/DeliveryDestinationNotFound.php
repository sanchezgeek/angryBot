<?php

declare(strict_types=1);

namespace App\Delivery\Application\Exception;

final class DeliveryDestinationNotFound extends \RuntimeException
{
    public static function forAddress(string $address): self
    {
        return new self(
            \sprintf('Cannot find `%s` geo to calculate distance.', $address),
        );
    }
}
