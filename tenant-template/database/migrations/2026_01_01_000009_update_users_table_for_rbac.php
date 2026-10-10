<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateUsersTableForRbac extends Migration
{
    public function up()
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                // Drop the old ENUM column if it exists
                if (Schema::hasColumn('users', 'role')) {
                    $table->dropColumn('role');
                }

                // Make branch_id nullable if not already (for global admins)
                // Note: SQLite has limited support for changing columns, but since we recreate tables
                // or are on a fresh run, it's safer to just drop and recreate if needed, or simply 
                // modify it if the driver supports it. For this template, let's assume standard behavior.
                $table->uuid('branch_id')->nullable()->change();

                // Add role_id and pos_pin
                $table->foreignId('role_id')->nullable()->constrained('roles')->onDelete('restrict');
                $table->string('pos_pin', 60)->nullable();
            });
        }
    }

    public function down()
    {
        Schema::disableForeignKeyConstraints();
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['role_id']);
                $table->dropColumn(['role_id', 'pos_pin']);
                $table->enum('role', ['admin', 'manager', 'teller'])->default('teller');
                // Revert branch_id to be required
                $table->uuid('branch_id')->nullable(false)->change();
            });
        }
        Schema::enableForeignKeyConstraints();
    }
}
