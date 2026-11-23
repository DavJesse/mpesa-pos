<?php

namespace App\Modules\Mpesa\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Mpesa\Jobs\ProcessMpesaCallbackJob;
use App\Modules\System\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    /**
     * Handle incoming Safaricom Lipa Na M-Pesa confirmation callback.
     *
     * Validates tenant security token, dispatches async job, and immediately
     * acknowledges Safaricom within sub-second latency.
     */
    public function handleCallback(Request $request, string $tenant_secure_token): JsonResponse
    {
        $setting = SystemSetting::where('key', 'daraja_credentials')->first();
        $payload = $setting && is_array($setting->payload)
            ? $setting->payload
            : ($setting ? json_decode($setting->payload, true) : []);

        $storedToken = $payload['callback_token'] ?? null;

        // Security Signature Verification: Reject if token is missing or mismatched
        if (empty($storedToken) || !hash_equals((string) $storedToken, (string) $tenant_secure_token)) {
            Log::warning('Unauthorized M-Pesa callback attempt detected', [
                'provided_token' => $tenant_secure_token,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Invalid security signature.',
            ], 403);
        }

        $callbackData = $request->all();

        Log::info('Safaricom Callback Received - Dispatching Queue Job', [
            'token' => substr($tenant_secure_token, 0, 8) . '...',
        ]);

        // Dispatch asynchronous queued job to Redis for processing & broadcasting
        ProcessMpesaCallbackJob::dispatch($callbackData);

        // Immediate sub-second acknowledgment to Safaricom handshake
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ], 200);
    }
}
