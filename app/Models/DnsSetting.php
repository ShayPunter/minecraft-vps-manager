<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin → DNS (#5338). One row at most. Only DnsController writes it, so nothing is mass assigned.
 *
 * @property int $id
 * @property string $domain the zone's name, e.g. example.com
 * @property string $zone_id Cloudflare's zone id
 * @property string $api_token encrypted with APP_KEY
 * @property string|null $waker_ipv4 the public address `servers:waker` listens on (#5620); null = no waker
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class DnsSetting extends Model
{
    /**
     * Never in toArray()/JSON, so no page, log or API answer can carry the token.
     *
     * @var list<string>
     */
    protected $hidden = ['api_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
        ];
    }
}
