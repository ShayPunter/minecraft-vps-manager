<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modpacks (#5352): a server-pack zip URL, or a Modrinth project + version. heap_mb is
        // the -Xms/-Xmx prepare.sh writes into the pack's user_jvm_args.txt on every start.
        Schema::table('servers', function (Blueprint $table) {
            $table->string('pack_url', 2048)->nullable();
            $table->string('modrinth_project_id', 16)->nullable();
            $table->string('modrinth_version_id', 16)->nullable();
            $table->string('modrinth_title', 200)->nullable();
            $table->unsignedInteger('heap_mb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['pack_url', 'modrinth_project_id', 'modrinth_version_id', 'modrinth_title', 'heap_mb']);
        });
    }
};
