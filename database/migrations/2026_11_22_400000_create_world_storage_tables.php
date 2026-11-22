<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where worlds live between sessions (#5288): one SFTP or FTP(S) server, set in
        // Admin → World storage. One row at most; the env is the fallback while there is none.
        // The password and private key are encrypted with APP_KEY (model casts).
        Schema::create('world_storage_settings', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 10);
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('username');
            $table->text('password')->nullable();
            $table->text('private_key')->nullable();
            $table->string('root');
            $table->boolean('ftps')->default(true);
            // The SFTP server's public host key, pinned when the settings are saved: the app
            // and every VPS refuse a server that answers with another key.
            $table->text('host_key')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One archive of a server's folder on the storage server. The newest few are kept.
        Schema::create('world_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('source', 10); // upload, save
            $table->foreignId('server_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The archive the next start downloads.
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('current_world_archive_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('current_world_archive_id');
        });
        Schema::dropIfExists('world_archives');
        Schema::dropIfExists('world_storage_settings');
    }
};
