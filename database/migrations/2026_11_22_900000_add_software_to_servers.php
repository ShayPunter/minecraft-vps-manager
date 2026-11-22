<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a server runs (#5351): `custom` (the admin's own start command, every server
        // made before this) or Vanilla/Paper/Fabric at a Minecraft version, installed by the
        // first start that has no saved world.
        Schema::table('servers', function (Blueprint $table) {
            $table->string('software', 12)->default('custom');
            $table->string('minecraft_version', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['software', 'minecraft_version']);
        });
    }
};
