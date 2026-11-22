<?php

namespace App\Cloud;

final readonly class Location
{
    public function __construct(
        public string $name,
        public string $description,
        public string $city,
        public string $country,
    ) {}
}
