<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin → DNS (#5338): the Cloudflare zone servers get their names in. One row at most.
        // The API token is encrypted with APP_KEY (model cast).
        Schema::create('dns_settings', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('zone_id', 64);
            $table->text('api_token');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The name inside the zone ("atm9" → atm9.<domain>). One server per name.
        Schema::table('servers', function (Blueprint $table) {
            $table->string('subdomain', 190)->nullable()->unique();
        });

        // The A record a run made, so it can be deleted when the run ends.
        Schema::table('server_runs', function (Blueprint $table) {
            $table->string('dns_record_id', 64)->nullable();
            $table->string('dns_zone_id', 64)->nullable();
            $table->string('dns_name')->nullable();
            $table->text('dns_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_runs', function (Blueprint $table) {
            $table->dropColumn(['dns_record_id', 'dns_zone_id', 'dns_name', 'dns_error']);
        });
        Schema::table('servers', function (Blueprint $table) {
            $table->dropUnique(['subdomain']);
            $table->dropColumn('subdomain');
        });
        Schema::dropIfExists('dns_settings');
    }
};
