<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A run the waker started because someone joined a Wake on join server (#5818): not a
        // guest start, so it neither follows nor uses up the guest rules.
        Schema::table('server_runs', function (Blueprint $table) {
            $table->boolean('started_by_join')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('server_runs', function (Blueprint $table) {
            $table->dropColumn('started_by_join');
        });
    }
};
