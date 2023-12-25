<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('server_progress', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('ip_address', 45)->nullable();
            $table->string('server_id')->unique();
            $table->integer('progress');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('server_progress');
    }
};
