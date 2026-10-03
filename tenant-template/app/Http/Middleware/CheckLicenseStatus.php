<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckLicenseStatus
{
    public function handle(Request $request, Closure $next)
    {
        // Skip check if we are already on the subscription expired page
        if ($request->routeIs('subscription.expired')) {
            return $next($request);
        }

        $tenantUuid = config('tenant.uuid', env('TENANT_UUID')); 

        $status = Cache::remember('license_status', now()->addHours(12), function () use ($tenantUuid) {
            try {
                $landlordUrl = config('tenant.landlord_url', env('LANDLORD_URL', 'http://landlord/api/internal/verify-license/'));
                $response = Http::timeout(5)->get($landlordUrl . $tenantUuid);
                
                if ($response->successful()) {
                    return $response->json('status');
                }
                
                Log::error("Failed to verify license with landlord. Status: {$response->status()}");
                return 'unknown'; // Fail open slightly or 'expired' depending on strictness
            } catch (\Exception $e) {
                Log::error("Exception verifying license with landlord: " . $e->getMessage());
                return 'unknown';
            }
        });

        if (in_array($status, ['expired', 'past_due'])) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Subscription expired.'], 403);
            }
            return redirect()->route('subscription.expired');
        }

        return $next($request);
    }
}
