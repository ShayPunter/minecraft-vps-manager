<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // World storage part 2 (#5288): once the VPS is up, the app logs in, downloads the
        // server's current world and starts Minecraft (PrepareServerRun); the stop uploads a
        // new archive before the VPS is deleted.
        Schema::table('server_runs', function (Blueprint $table) {
            // Set when PrepareServerRun is queued, so it's queued once; cleared to retry.
            $table->timestamp('prepare_queued_at')->nullable();
            // The world is in place and Minecraft was started.
            $table->timestamp('prepared_at')->nullable();
            // The archive this run started from (null: a new world).
            $table->unsignedBigInteger('world_archive_id')->nullable();
            // The archive this run's stop saved, so a retried stop doesn't save twice.
            $table->unsignedBigInteger('saved_world_archive_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_runs', function (Blueprint $table) {
            $table->dropColumn(['prepare_queued_at', 'prepared_at', 'world_archive_id', 'saved_world_archive_id']);
        });
    }
};
