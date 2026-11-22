<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each run gets its own SSH key pair (#5324). The private half is encrypted with
        // APP_KEY (model cast) and wiped once the VPS is gone; the public half is registered
        // with the provider under provider_ssh_key_id and deleted there at the same time.
        Schema::table('server_runs', function (Blueprint $table) {
            $table->text('ssh_private_key')->nullable();
            $table->unsignedBigInteger('provider_ssh_key_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_runs', function (Blueprint $table) {
            $table->dropColumn(['ssh_private_key', 'provider_ssh_key_id']);
        });
    }
};
