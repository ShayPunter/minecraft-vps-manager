<?php

declare(strict_types=1);

namespace App\Enums;

enum LinodeType: string
{
    case SHARED_NANODE_1GB = 'g6-nanode-1';
    case SHARED_LINODE_2GB = 'g6-standard-1';
    case SHARED_LINODE_4GB = 'g6-standard-2';
    case SHARED_LINODE_8GB = 'g6-standard-4';
    case SHARED_LINODE_16GB = 'g6-standard-6';
    case SHARED_LINODE_32GB = 'g6-standard-8';
    case SHARED_LINODE_64GB = 'g6-standard-16';
    case SHARED_LINODE_96GB = 'g6-standard-20';
    case SHARED_LINODE_128GB = 'g6-standard-24';
    case SHARED_LINODE_192GB = 'g6-standard-32';
    case HIGHMEM_LINODE_24GB = 'g7-highmem-1';
    case HIGHMEM_LINODE_48GB = 'g7-highmem-2';
    case HIGHMEM_LINODE_90GB = 'g7-highmem-4';
    case HIGHMEM_LINODE_150GB = 'g7-highmem-8';
    case HIGHMEM_LINODE_300GB = 'g7-highmem-16';
    case DEDICATED_LINODE_4GB = 'g6-dedicated-2';
    case DEDICATED_LINODE_8GB = 'g6-dedicated-4';
    case DEDICATED_LINODE_16GB = 'g6-dedicated-8';
    case DEDICATED_LINODE_32GB = 'g6-dedicated-16';
    case DEDICATED_LINODE_64GB = 'g6-dedicated-32';
    case DEDICATED_LINODE_96GB = 'g6-dedicated-48';
    case DEDICATED_LINODE_128GB = 'g6-dedicated-50';
    case DEDICATED_LINODE_256GB = 'g6-dedicated-56';
    case DEDICATED_LINODE_512GB = 'g6-dedicated-64';
    case GPU_LINODE_32GB_1GPU = 'g1-gpu-rtx6000-1';
    case GPU_LINODE_64GB_2GPU = 'g1-gpu-rtx6000-2';
    case GPU_LINODE_96GB_3GPU = 'g1-gpu-rtx6000-3';
    case GPU_LINODE_128GB_4GPU = 'g1-gpu-rtx6000-4';
}
