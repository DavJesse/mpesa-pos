<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantLicenseController extends Controller
{
    public function verify($tenant_uuid)
    {
        $tenant = DB::table('tenants')->where('uuid', $tenant_uuid)->first();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $license = DB::table('licenses')
            ->where('tenant_id', $tenant->id)
            ->latest('expires_at')
            ->first();

        if (!$license) {
            return response()->json(['error' => 'License not found'], 404);
        }

        return response()->json([
            'status' => $license->status,
            'expires_at' => $license->expires_at
        ]);
    }
}
