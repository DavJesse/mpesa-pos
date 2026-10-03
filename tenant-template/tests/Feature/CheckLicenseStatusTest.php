<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Http\Middleware\CheckLicenseStatus;
use Tests\TestCase;
use Illuminate\Support\Facades\Route;

class CheckLicenseStatusTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Setup dummy routes for testing
        Route::get('/subscription-expired', function () {
            return 'Subscription Expired View';
        })->name('subscription.expired');
    }

    public function test_it_redirects_if_license_is_expired()
    {
        Http::fake([
            '*' => Http::response(['status' => 'expired'], 200),
        ]);

        $request = Request::create('/pos', 'GET');
        $middleware = new CheckLicenseStatus();

        $response = $middleware->handle($request, function () {
            return response('Next');
        });

        $this->assertTrue($response->isRedirect(route('subscription.expired')));
    }

    public function test_it_allows_request_if_license_is_active()
    {
        Http::fake([
            '*' => Http::response(['status' => 'active'], 200),
        ]);

        $request = Request::create('/pos', 'GET');
        $middleware = new CheckLicenseStatus();

        $response = $middleware->handle($request, function () {
            return response('Next');
        });

        $this->assertEquals('Next', $response->getContent());
    }
}
