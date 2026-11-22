<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Minutes with nobody on before the watchdog stops the server (#5283).
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedSmallInteger('idle_minutes')->default(15);
        });

        Schema::table('server_runs', function (Blueprint $table) {
            // What the last server-list ping said (null: no answer).
            $table->unsignedSmallInteger('players_online')->nullable();
            // The last time the ping counted at least one player.
            $table->timestamp('last_players_at')->nullable();
            // Why it was stopped: idle, admin, starter, boot_timeout.
            $table->string('stop_reason', 20)->nullable();
            // Set once the "running for a long time" alert has gone out.
            $table->timestamp('long_run_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_runs', function (Blueprint $table) {
            $table->dropColumn(['players_online', 'last_players_at', 'stop_reason', 'long_run_alerted_at']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('idle_minutes');
        });
    }
};
