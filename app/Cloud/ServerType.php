<?php

namespace App\Cloud;

final readonly class ServerType
{
    /**
     * @param  array<string, string>  $monthlyGrossPrices  location name => gross monthly price in EUR
     */
    public function __construct(
        public string $name,
        public string $description,
        public int $cores,
        public float $memoryGb,
        public int $diskGb,
        public string $cpuType,
        public string $architecture,
        public bool $deprecated,
        public array $monthlyGrossPrices,
    ) {}

    public function availableIn(string $location): bool
    {
        return array_key_exists($location, $this->monthlyGrossPrices);
    }
}
