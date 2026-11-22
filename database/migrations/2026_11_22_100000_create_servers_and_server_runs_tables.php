<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Minecraft server's definition. Its VPS only exists while a run is active.
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 40)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('icon_url', 2048)->nullable();
            $table->string('server_type', 64);
            $table->string('location', 64);
            $table->string('start_command', 500)->default('./run.sh');
            $table->unsignedSmallInteger('java_version')->default(21);
            $table->timestamps();
        });

        // One VPS lifetime: created on Start, gone when the run ends.
        Schema::create('server_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // Equal to server_id while the run is active, null once it has ended. Unique, so
            // a server can never have two active runs (NULLs don't clash).
            $table->unsignedBigInteger('active_server_id')->nullable()->unique();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('started_from_ip', 45)->nullable();
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('provider_server_id')->nullable();
            $table->string('ipv4', 45)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('online_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_runs');
        Schema::dropIfExists('servers');
    }
};
