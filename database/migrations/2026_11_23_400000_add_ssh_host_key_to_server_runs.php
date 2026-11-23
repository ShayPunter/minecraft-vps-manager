<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The VPS's SSH host key (#5527): made by the app per run, handed to cloud-init, and
        // pinned on every SSH/SFTP login. Only the public half is kept. Null for older runs.
        Schema::table('server_runs', function (Blueprint $table) {
            $table->string('ssh_host_key', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_runs', function (Blueprint $table) {
            $table->dropColumn('ssh_host_key');
        });
    }
};
