<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Minecraft EULA, accepted per server by an admin (#5367, from #5351). Starts then
        // write eula.txt with eula=true before Minecraft runs.
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('eula_accepted_at')->nullable();
            $table->foreignId('eula_accepted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Each run's VPS posts its console log here (#5367). The token only appends to this
        // run's log; only its SHA-256 is kept.
        Schema::table('server_runs', function (Blueprint $table) {
            $table->char('log_token_hash', 64)->nullable();
        });

        Schema::create('server_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_run_id')->constrained()->cascadeOnDelete();
            $table->string('source', 12); // setup, prepare, minecraft, fallback
            $table->text('line');
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['server_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_run_logs');

        Schema::table('server_runs', function (Blueprint $table) {
            $table->dropColumn('log_token_hash');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('eula_accepted_by');
            $table->dropColumn('eula_accepted_at');
        });
    }
};
