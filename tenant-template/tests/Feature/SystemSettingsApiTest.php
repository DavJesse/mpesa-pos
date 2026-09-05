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
        $branch = Branch::create(['name' => 'HQ', 'location' => 'NBI']);
        $user = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branch->id
        ]);

        $response = $this->actingAs($user)->getJson('/api/system-settings/mpesa');
        
        $response->assertStatus(403);
    }

    public function test_admin_can_access_system_settings()
    {
        $branch = Branch::create(['name' => 'HQ', 'location' => 'NBI']);
        $user = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id
        ]);

        $response = $this->actingAs($user)->getJson('/api/system-settings/mpesa');
        
        $response->assertStatus(200);
        $response->assertJson(['key' => 'mpesa_credentials']);
    }
}
