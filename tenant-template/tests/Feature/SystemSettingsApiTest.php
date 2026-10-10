<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Modules\Branches\Models\Branch;

class SystemSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_teller_cannot_access_system_settings()
    {
        $branch = Branch::factory()->create(['name' => 'HQ', 'location' => 'NBI']);
        $role = \App\Modules\Auth\Models\Role::firstOrCreate(['name' => 'teller', 'level' => 10]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id
        ]);

        $response = $this->actingAs($user)->getJson('/api/system-settings/mpesa');
        
        $response->assertStatus(403);
    }

    public function test_admin_can_access_system_settings()
    {
        $branch = Branch::factory()->create(['name' => 'HQ', 'location' => 'NBI']);
        $role = \App\Modules\Auth\Models\Role::firstOrCreate(['name' => 'admin', 'level' => 100]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id
        ]);

        $response = $this->actingAs($user)->getJson('/api/system-settings/mpesa');
        
        $response->assertStatus(200);
        $response->assertJson(['key' => 'mpesa_credentials']);
    }
}
