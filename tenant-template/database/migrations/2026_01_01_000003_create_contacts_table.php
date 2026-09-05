<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateContactsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('contacts')) {
            Schema::create('contacts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name', 50);
                $table->string('phone_number', 15)->unique();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down()
    {
        Schema::disableForeignKeyConstraints();
        if (Schema::hasTable('contacts')) {
            Schema::dropIfExists('contacts');
        }
        Schema::enableForeignKeyConstraints();
    }
}
