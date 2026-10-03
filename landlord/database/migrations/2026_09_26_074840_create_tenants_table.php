<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->string('company_name', 100);
                $table->string('first_name', 50)->nullable();
                $table->string('last_name', 50)->nullable();
                $table->string('email', 150)->unique();
                $table->string('phone_number', 20)->nullable();
                $table->string('subdomain', 60)->unique();
                $table->string('custom_domain', 100)->unique()->nullable();
                $table->string('status', 20)->default('active'); // active, suspended, provisioning
                $table->string('docker_container_id', 64)->nullable();
                $table->integer('host_container_port')->unique()->nullable();
                $table->integer('host_ssh_port')->unique()->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('tenants');
    }
}
