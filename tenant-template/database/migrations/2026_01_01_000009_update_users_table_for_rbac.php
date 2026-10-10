<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateUsersTableForRbac extends Migration
{
    public function up()
    {
        if (Schema::hasTable('users')) {
            // Step 1: Drop old columns
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'role')) {
                    $table->dropColumn('role');
                }
            });

            // Step 2: Change existing columns
            Schema::table('users', function (Blueprint $table) {
                $table->uuid('branch_id')->nullable()->change();
            });

            // Step 3: Add new columns
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('role_id')->nullable()->constrained('roles')->onDelete('restrict');
                $table->string('pos_pin', 60)->nullable();
            });
        }
    }

    public function down()
    {
        Schema::disableForeignKeyConstraints();
        if (Schema::hasTable('users')) {
            // Step 1: Drop new columns
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['role_id']);
                $table->dropColumn(['role_id', 'pos_pin']);
            });

            // Step 2: Add old ENUM back
            Schema::table('users', function (Blueprint $table) {
                $table->enum('role', ['admin', 'manager', 'teller'])->default('teller');
            });

            // Step 3: Revert branch_id
            Schema::table('users', function (Blueprint $table) {
                $table->uuid('branch_id')->nullable(false)->change();
            });
        }
        Schema::enableForeignKeyConstraints();
    }
}
