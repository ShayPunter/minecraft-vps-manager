<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wake on join (#5620): while a server with this on is stopped, its name points at the
        // waker (`servers:waker`), which shows it as asleep in the server list and starts it
        // when someone joins.
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('wake_on_join')->default(false);
        });

        // The public IPv4 address the waker listens on (Admin → DNS). Empty = no waker.
        Schema::table('dns_settings', function (Blueprint $table) {
            $table->string('waker_ipv4', 15)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dns_settings', function (Blueprint $table) {
            $table->dropColumn('waker_ipv4');
        });
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('wake_on_join');
        });
    }
};
