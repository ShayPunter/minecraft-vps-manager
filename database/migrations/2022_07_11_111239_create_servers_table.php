<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->nullable('0.0.0.0');
            $table->string('server_id')->unique();
            $table->integer('last_activity')->default(0);
            $table->string('status')->default('offline');
            $table->string('name')->default('no name given');
            $table->string('file');
            $table->string('server_icon')->nullable();
            $table->string('is_public')->default('false');
            $table->string('provider')->default('linode');
            $table->string('server_size')->default(\App\Enums\LinodeType::SHARED_NANODE_1GB);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
