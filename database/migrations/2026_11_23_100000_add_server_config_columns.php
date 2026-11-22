<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What admins changed in the app (#5377): written over the world's own files before
        // every start. Null = leave the world's file alone.
        Schema::table('servers', function (Blueprint $table) {
            $table->json('properties')->nullable(); // server.properties key => value
            $table->json('whitelist')->nullable(); // [{uuid, name}]
            $table->json('ops')->nullable(); // [{uuid, name, level}]
            $table->text('run_sh')->nullable();
            $table->text('user_jvm_args')->nullable();
        });

        // The world's own versions of those files, as uploaded or saved, for the form.
        Schema::table('world_archives', function (Blueprint $table) {
            $table->json('files')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('world_archives', function (Blueprint $table) {
            $table->dropColumn('files');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['properties', 'whitelist', 'ops', 'run_sh', 'user_jvm_args']);
        });
    }
};
