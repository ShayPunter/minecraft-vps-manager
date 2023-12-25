<?php

declare(strict_types=1);

namespace App\Enums;

enum LinodeRegion: string
{
    case AP_WEST = 'ap-west';
    case CA_CENTRAL = 'ca-central';
    case AP_SOUTHWEST = 'ap-southwest';
    case US_CENTRAL = 'us-central';
    case US_WEST = 'us-west';
    case US_SOUTHEAST = 'us-southeast';
    case US_EAST = 'us-east';
    case EU_EAST = 'eu-east';
    case AP_SOUTH = 'ap-south';
    case EU_CENTRAL = 'eu-central';
    case AP_NORTHEAST = 'ap-northeast';
}
