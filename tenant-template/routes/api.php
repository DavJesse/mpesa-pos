<?php

use App\Modules\Mpesa\Http\Controllers\MpesaCallbackController;
use App\Modules\Mpesa\Http\Controllers\MpesaStkController;
use App\Modules\Mpesa\Models\Transaction;
use App\Modules\System\Http\Controllers\MpesaSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Communication between backend and frontend is strictly via REST APIs.
|
*/

// Public Safaricom Daraja Webhook Callbacks (Protected by tenant_secure_token signature)
Route::post('/mpesa/callback/{tenant_secure_token}', [MpesaCallbackController::class, 'handleCallback']);
Route::post('/v1/mpesa/callback/{tenant_secure_token}', [MpesaCallbackController::class, 'handleCallback']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/transactions', function (Request $request) {
        return $request->has('page')
            ? Transaction::latest()->paginate(20)
            : Transaction::all();
    });

    // M-Pesa STK Push trigger
    Route::post('/mpesa/stkpush', [MpesaStkController::class, 'initiate']);
    Route::post('/v1/mpesa/stkpush', [MpesaStkController::class, 'initiate']);

    // Admin-only Settings
    Route::middleware('role:admin')->group(function () {
        Route::get('/system-settings/mpesa', function (Request $request) {
            return response()->json(['key' => 'mpesa_credentials', 'payload' => '...']);
        });

        Route::prefix('admin/settings')->group(function () {
            Route::get('/mpesa', [MpesaSettingsController::class, 'show']);
            Route::post('/mpesa', [MpesaSettingsController::class, 'update']);
        });

        Route::prefix('v1/admin/settings')->group(function () {
            Route::get('/mpesa', [MpesaSettingsController::class, 'show']);
            Route::post('/mpesa', [MpesaSettingsController::class, 'update']);
        });
    });
});
