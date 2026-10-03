<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Carbon\Carbon;

class AuditLicensesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flags_expired_licenses()
    {
        // Setup Tenant
        $tenantId = DB::table('tenants')->insertGetId([
            'uuid' => 'test-uuid-123',
            'company_name' => 'Test Company',
            'email' => 'test@example.com',
            'subdomain' => 'test',
        ]);

        // Setup Active License (Not Expired)
        DB::table('licenses')->insert([
            'tenant_id' => $tenantId,
            'license_key' => 'active-key',
            'status' => 'active',
            'expires_at' => Carbon::now()->addDays(5),
        ]);

        // Setup Expired License
        $expiredLicenseId = DB::table('licenses')->insertGetId([
            'tenant_id' => $tenantId,
            'license_key' => 'expired-key',
            'status' => 'active',
            'expires_at' => Carbon::now()->subDays(1),
        ]);

        // Run the command
        $this->artisan('licenses:audit')
            ->assertExitCode(0);

        // Assert Active remains active
        $this->assertDatabaseHas('licenses', [
            'license_key' => 'active-key',
            'status' => 'active',
        ]);

        // Assert Expired is flagged
        $this->assertDatabaseHas('licenses', [
            'id' => $expiredLicenseId,
            'status' => 'expired',
        ]);
    }
}
