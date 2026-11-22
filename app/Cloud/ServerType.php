<?php

namespace App\Cloud;

final readonly class ServerType
{
    /**
     * @param  string  $category  the provider's grouping, e.g. "Shared vCPU" (#5321)
     * @param  array<string, array{monthly: string, hourly: string}>  $grossPrices  location name => gross EUR prices,
     *                                                                              only for locations where the type can still be ordered
     * @param  list<string>  $retiredIn  locations where the provider has deprecated the type
     */
    public function __construct(
        public string $name,
        public string $description,
        public int $cores,
        public float $memoryGb,
        public int $diskGb,
        public string $cpuType,
        public string $architecture,
        public string $category,
        public array $grossPrices,
        public array $retiredIn = [],
    ) {}

    public function availableIn(string $location): bool
    {
        return array_key_exists($location, $this->grossPrices);
    }

    public function retiredIn(string $location): bool
    {
        return in_array($location, $this->retiredIn, true);
    }

    public function monthlyPrice(string $location): ?float
    {
        return isset($this->grossPrices[$location]) ? (float) $this->grossPrices[$location]['monthly'] : null;
    }
}
