<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMpesaCallbackFieldsToTransactionsTable extends Migration
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
                if (!Schema::hasColumn('transactions', 'mpesa_receipt_number')) {
                    $table->string('mpesa_receipt_number', 20)->nullable()->index()->after('mpesa_customer_message');
                }
                if (!Schema::hasColumn('transactions', 'mpesa_result_code')) {
                    $table->string('mpesa_result_code', 10)->nullable()->after('mpesa_receipt_number');
                }
                if (!Schema::hasColumn('transactions', 'mpesa_result_desc')) {
                    $table->string('mpesa_result_desc', 150)->nullable()->after('mpesa_result_code');
                }
                if (!Schema::hasColumn('transactions', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('mpesa_result_desc');
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
                if (Schema::hasColumn('transactions', 'mpesa_receipt_number')) {
                    $columns[] = 'mpesa_receipt_number';
                }
                if (Schema::hasColumn('transactions', 'mpesa_result_code')) {
                    $columns[] = 'mpesa_result_code';
                }
                if (Schema::hasColumn('transactions', 'mpesa_result_desc')) {
                    $columns[] = 'mpesa_result_desc';
                }
                if (Schema::hasColumn('transactions', 'paid_at')) {
                    $columns[] = 'paid_at';
                }
                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
}
