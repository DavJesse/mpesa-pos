<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LicenseWebhookController extends Controller
{
    public function licenseRenewed(Request $request)
    {
        $expectedToken = config('tenant.landlord_webhook_secret', env('LANDLORD_WEBHOOK_SECRET'));
        if ($expectedToken && $request->header('X-Webhook-Secret') !== $expectedToken) {
            Log::warning('Unauthorized license renewal webhook attempt.');
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Cache::forget('license_status');
        
        Log::info('License cache cleared via webhook after successful renewal.');

        return response()->json(['message' => 'License cache cleared successfully.']);
    }
}
