<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLicensesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('licenses')) {
            Schema::create('licenses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('license_key', 100)->unique();
                $table->string('status', 20)->default('trialing'); // trialing, active, past_due, expired
                $table->integer('billing_duration_months')->default(1);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('canceled_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('licenses');
    }
}
