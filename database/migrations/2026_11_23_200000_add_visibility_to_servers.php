<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public / Private / Link only (#5462) in place of the is_public flag. Existing servers keep
 * what they had: public stays public, the rest private. A link-only server has a share
 * token: encrypted so Admin can show the link again, and its SHA-256 for the lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('visibility', 16)->default('private')->after('description');
            $table->text('share_token')->nullable()->after('visibility');
            $table->string('share_token_hash', 64)->nullable()->unique()->after('share_token');
        });

        DB::table('servers')->where('is_public', true)->update(['visibility' => 'public']);

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('description');
        });

        DB::table('servers')->where('visibility', 'public')->update(['is_public' => true]);

        Schema::table('servers', function (Blueprint $table) {
            $table->dropUnique(['share_token_hash']);
            $table->dropColumn(['visibility', 'share_token', 'share_token_hash']);
        });
    }
};
