<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/transactions', function (Request $request) {
        return \App\Modules\Mpesa\Models\Transaction::all();
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/system-settings/mpesa', function (Request $request) {
            // Check policy just to be doubly sure, although middleware catches it first
            if ($request->user()->cannot('viewAny', \App\Modules\System\Models\SystemSetting::class)) {
                abort(403);
            }
            return response()->json(['key' => 'mpesa_credentials', 'payload' => '...']);
        });
    });
});
