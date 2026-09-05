<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSystemSettingsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('key', 50)->unique();
                $table->json('payload');
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down()
    {
        Schema::disableForeignKeyConstraints();
        if (Schema::hasTable('system_settings')) {
            Schema::dropIfExists('system_settings');
        }
        Schema::enableForeignKeyConstraints();
    }
}
