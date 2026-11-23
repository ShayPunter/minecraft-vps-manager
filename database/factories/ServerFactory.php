<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->unique()->word();

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => null,
            'visibility' => Visibility::Private,
            'icon_url' => null,
            'server_type' => 'cx33',
            'location' => 'fsn1',
            'start_command' => './run.sh',
            'java_version' => 21,
            'idle_minutes' => 15,
        ];
    }

    public function public(): static
    {
        return $this->state(fn () => ['visibility' => Visibility::Public]);
    }

    public function linkOnly(): static
    {
        return $this->state(fn () => ['visibility' => Visibility::Link]);
    }
}
