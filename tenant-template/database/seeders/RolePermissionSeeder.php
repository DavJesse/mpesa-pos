<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = config('rbac.permissions');
        $roles = config('rbac.roles');
        $assignments = config('rbac.assignments');

        // 1. Insert permissions
        $permissionIds = [];
        foreach ($permissions as $name => $description) {
            $permission = DB::table('permissions')->where('name', $name)->first();
            if (!$permission) {
                $id = DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $permissionIds[$name] = $id;
            } else {
                $permissionIds[$name] = $permission->id;
            }
        }

        // 2. Insert roles and attach permissions
        foreach ($roles as $roleName => $roleData) {
            $role = DB::table('roles')->where('name', $roleName)->first();
            if (!$role) {
                $roleId = DB::table('roles')->insertGetId([
                    'name' => $roleName,
                    'level' => $roleData['level'],
                    'is_system' => $roleData['is_system'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $roleId = $role->id;
                // Optionally update level/is_system if they changed
                DB::table('roles')->where('id', $roleId)->update([
                    'level' => $roleData['level'],
                    'is_system' => $roleData['is_system'],
                    'updated_at' => now(),
                ]);
            }

            // Sync permissions for this role based on assignments
            if (isset($assignments[$roleName])) {
                // Clear existing permissions for this role to ensure a clean sync
                DB::table('role_permissions')->where('role_id', $roleId)->delete();

                $rolePermissions = [];
                foreach ($assignments[$roleName] as $permName) {
                    if (isset($permissionIds[$permName])) {
                        $rolePermissions[] = [
                            'role_id' => $roleId,
                            'permission_id' => $permissionIds[$permName],
                        ];
                    }
                }

                if (!empty($rolePermissions)) {
                    DB::table('role_permissions')->insert($rolePermissions);
                }
            }
        }
    }
}
