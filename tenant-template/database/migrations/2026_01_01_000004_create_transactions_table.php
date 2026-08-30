<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('branch_id')->constrained('branches')->onDelete('restrict');
                $table->foreignUuid('user_id')->nullable()->constrained('users')->onDelete('set null');
                $table->foreignUuid('contact_id')->constrained('contacts')->onDelete('restrict');
                $table->decimal('amount', 15, 2);
                $table->string('payment_method', 20);
                $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
                $table->string('mpesa_checkout_request_id', 50)->nullable();
                $table->string('mpesa_merchant_request_id', 50)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('transactions')) {
            Schema::dropIfExists('transactions');
        }
    }
}
