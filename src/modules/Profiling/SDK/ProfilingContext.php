<?php

declare(strict_types=1);

namespace App\Profiling\SDK;

use App\Profiling\Application\Collector\ProfilingPointsStaticCollector;
use JsonSerializable;

final readonly class ProfilingContext implements JsonSerializable
{
    public function __construct(
        public string $uniqId
    ) {
    }

    public static function create(string $uniqId): self
    {
        return new self($uniqId);
    }

    public static function fromStored(array $data): self
    {
        return new self($data['uniqId']);
    }

    public function jsonSerialize(): array
    {
        return ['uniqId' => $this->uniqId];
    }

    public function registerNewPoint(string $info): ProfilingPointDto
    {
        ProfilingPointsStaticCollector::addPoint(
            $point = ProfilingPointDto::create($info, $this)
        );

        return $point;
    }
}
