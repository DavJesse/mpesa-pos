<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMpesaResponseFieldsToTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('transactions', 'mpesa_response_code')) {
                    $table->string('mpesa_response_code', 10)->nullable()->after('mpesa_merchant_request_id');
                }
                if (!Schema::hasColumn('transactions', 'mpesa_response_description')) {
                    $table->string('mpesa_response_description', 100)->nullable()->after('mpesa_response_code');
                }
                if (!Schema::hasColumn('transactions', 'mpesa_customer_message')) {
                    $table->string('mpesa_customer_message', 150)->nullable()->after('mpesa_response_description');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                $columns = [];
                if (Schema::hasColumn('transactions', 'mpesa_response_code')) {
                    $columns[] = 'mpesa_response_code';
                }
                if (Schema::hasColumn('transactions', 'mpesa_response_description')) {
                    $columns[] = 'mpesa_response_description';
                }
                if (Schema::hasColumn('transactions', 'mpesa_customer_message')) {
                    $columns[] = 'mpesa_customer_message';
                }
                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
}

