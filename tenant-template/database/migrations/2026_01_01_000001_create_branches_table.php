<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBranchesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name', 50);
                $table->string('location', 100);
                $table->string('mpesa_shortcode_override_nullable', 15)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('branches')) {
            Schema::dropIfExists('branches');
        }
    }
}
