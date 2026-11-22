<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // S3-compatible world storage (#5347). The access key goes in `username` and the
        // secret key in `password` (encrypted), so they're kept, hidden and removed the same
        // way as an SFTP login. `host`/`port` hold the endpoint's host for logs and messages.
        Schema::table('world_storage_settings', function (Blueprint $table) {
            $table->string('endpoint')->nullable();
            $table->string('region', 40)->nullable();
            $table->string('bucket', 63)->nullable();
            $table->boolean('path_style')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('world_storage_settings', function (Blueprint $table) {
            $table->dropColumn(['endpoint', 'region', 'bucket', 'path_style']);
        });
    }
};
