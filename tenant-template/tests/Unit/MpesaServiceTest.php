<?php

namespace Tests\Unit;

use App\Modules\Mpesa\Services\MpesaService;
use App\Modules\System\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class MpesaServiceTest extends TestCase
{
    use RefreshDatabase;

    // --------------------------------------------------------------------------
    // 1. Phone Number Normalization Tests
    // --------------------------------------------------------------------------

    public function test_normalizes_standard_kenyan_phone_numbers()
    {
        $this->assertEquals('254712345678', MpesaService::normalizePhoneNumber('0712345678'));
        $this->assertEquals('254712345678', MpesaService::normalizePhoneNumber('+254712345678'));
        $this->assertEquals('254712345678', MpesaService::normalizePhoneNumber('254712345678'));
        $this->assertEquals('254712345678', MpesaService::normalizePhoneNumber('712345678'));
        $this->assertEquals('254712345678', MpesaService::normalizePhoneNumber(' 0712 345 678 '));
        $this->assertEquals('254712345678', MpesaService::normalizePhoneNumber('0712-345-678'));
        $this->assertEquals('254712345678', MpesaService::normalizePhoneNumber('(+254) 712-345-678'));
    }

    public function test_normalizes_01x_prefix_numbers()
    {
        $this->assertEquals('254112345678', MpesaService::normalizePhoneNumber('0112345678'));
        $this->assertEquals('254112345678', MpesaService::normalizePhoneNumber('+254112345678'));
        $this->assertEquals('254112345678', MpesaService::normalizePhoneNumber('112345678'));
        $this->assertEquals('254112345678', MpesaService::normalizePhoneNumber('254112345678'));
    }

    public function test_rejects_empty_or_whitespace_phone_numbers()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone number cannot be empty.');
        MpesaService::normalizePhoneNumber('   ');
    }

    public function test_rejects_alphabetic_phone_numbers()
    {
        $this->expectException(InvalidArgumentException::class);
        MpesaService::normalizePhoneNumber('0712abc678');
    }

    public function test_rejects_invalid_phone_numbers()
    {
        $this->expectException(InvalidArgumentException::class);
        MpesaService::normalizePhoneNumber('07123'); // Too short
    }

    public function test_rejects_foreign_or_invalid_prefix_numbers()
    {
        $this->expectException(InvalidArgumentException::class);
        MpesaService::normalizePhoneNumber('+14155552671'); // US number
    }

    // --------------------------------------------------------------------------
    // 2. Configuration & Database Decryption Tests
    // --------------------------------------------------------------------------

    public function test_is_configured_returns_correct_boolean()
    {
        $unconfigured = new MpesaService([]);
        $this->assertFalse($unconfigured->isConfigured());

        $partial = new MpesaService([
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => '', // missing passkey
        ]);
        $this->assertFalse($partial->isConfigured());

        $complete = new MpesaService([
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
        ]);
        $this->assertTrue($complete->isConfigured());
    }

    public function test_loads_and_decrypts_credentials_from_database()
    {
        SystemSetting::create([
            'key' => 'daraja_credentials',
            'payload' => [
                'consumer_key' => Crypt::encryptString('MyEncryptedKey'),
                'consumer_secret' => Crypt::encryptString('MyEncryptedSecret'),
                'passkey' => Crypt::encryptString('MyEncryptedPasskey'),
                'shortcode' => '174379',
                'env' => 'production',
                'shortcode_type' => 'till',
            ],
        ]);

        $service = new MpesaService(); // default constructor, pulls from DB

        $this->assertTrue($service->isConfigured());
        $settings = $service->getSettings();

        $this->assertEquals('MyEncryptedKey', $settings['consumer_key']);
        $this->assertEquals('MyEncryptedSecret', $settings['consumer_secret']);
        $this->assertEquals('MyEncryptedPasskey', $settings['passkey']);
        $this->assertEquals('174379', $settings['shortcode']);
        $this->assertEquals('production', $settings['env']);
        $this->assertEquals('till', $settings['shortcode_type']);
        $this->assertEquals(MpesaService::PRODUCTION_URL, $service->getBaseUrl());
    }

    public function test_resolves_sandbox_and_production_urls()
    {
        $sandboxService = new MpesaService([
            'env' => 'sandbox',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
        ]);
        $this->assertEquals(MpesaService::SANDBOX_URL, $sandboxService->getBaseUrl());

        $prodService = new MpesaService([
            'env' => 'production',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
        ]);
        $this->assertEquals(MpesaService::PRODUCTION_URL, $prodService->getBaseUrl());
    }

    // --------------------------------------------------------------------------
    // 3. OAuth Token Generation, Caching & Exception Tests
    // --------------------------------------------------------------------------

    public function test_generates_and_caches_oauth_token()
    {
        Cache::flush();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'mock_token_abc_123',
                'expires_in' => '3599',
            ], 200),
        ]);

        $service = new MpesaService([
            'env' => 'sandbox',
            'consumer_key' => 'test_key',
            'consumer_secret' => 'test_secret',
            'shortcode' => '174379',
            'passkey' => 'test_passkey',
        ]);

        $token1 = $service->getAccessToken();
        $this->assertEquals('mock_token_abc_123', $token1);

        // Subsequent call must retrieve from cache (Http::fake should only have 1 request)
        $token2 = $service->getAccessToken();
        $this->assertEquals('mock_token_abc_123', $token2);

        Http::assertSentCount(1);
    }

    public function test_oauth_token_throws_exception_when_credentials_missing()
    {
        $service = new MpesaService([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Daraja credentials (consumer_key / consumer_secret) are not configured.');

        $service->getAccessToken();
    }

    public function test_oauth_token_throws_exception_on_http_failure()
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'errorMessage' => 'Invalid Consumer Key',
            ], 400),
        ]);

        $service = new MpesaService([
            'env' => 'sandbox',
            'consumer_key' => 'bad_key',
            'consumer_secret' => 'bad_secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve Daraja access token');

        $service->getAccessToken();
    }

    public function test_oauth_token_throws_exception_when_access_token_is_missing()
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'status' => 'success', // missing access_token key
            ], 200),
        ]);

        $service = new MpesaService([
            'env' => 'sandbox',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Daraja OAuth response missing access_token.');

        $service->getAccessToken();
    }

    public function test_clear_token_cache_removes_token_from_cache()
    {
        Cache::put('daraja:oauth_token:174379', 'my_token', 3300);
        $this->assertTrue(Cache::has('daraja:oauth_token:174379'));

        $service = new MpesaService([
            'shortcode' => '174379',
        ]);
        $service->clearTokenCache();

        $this->assertFalse(Cache::has('daraja:oauth_token:174379'));
    }

    // --------------------------------------------------------------------------
    // 4. STK Push Dispatch, Cryptography & Boundary Tests
    // --------------------------------------------------------------------------

    public function test_stk_push_throws_exception_when_not_configured()
    {
        $service = new MpesaService([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Daraja API credentials are not configured.');

        $service->initiateStkPush([
            'phone_number' => '0712345678',
            'amount' => 100,
        ]);
    }

    public function test_stk_push_throws_exception_for_zero_or_negative_amount()
    {
        $service = new MpesaService([
            'env' => 'sandbox',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction amount must be greater than zero.');

        $service->initiateStkPush([
            'phone_number' => '0712345678',
            'amount' => 0,
        ]);
    }

    public function test_dispatches_stk_push_and_returns_identifiers()
    {
        Cache::flush();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'mock_token',
                'expires_in' => '3599',
            ], 200),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => '29115-34620561-1',
                'CheckoutRequestID' => 'ws_CO_191220261025000000',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ], 200),
        ]);

        $passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
        $shortcode = '174379';

        $service = new MpesaService([
            'env' => 'sandbox',
            'consumer_key' => 'test_key',
            'consumer_secret' => 'test_secret',
            'shortcode' => $shortcode,
            'passkey' => $passkey,
            'shortcode_type' => 'paybill',
        ]);

        $result = $service->initiateStkPush([
            'phone_number' => '0712345678',
            'amount' => 150.50,
            'account_reference' => 'INV1001',
            'transaction_desc' => 'Test Payment',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('29115-34620561-1', $result['merchant_request_id']);
        $this->assertEquals('ws_CO_191220261025000000', $result['checkout_request_id']);
        $this->assertEquals('0', $result['response_code']);

        Http::assertSent(function ($request) use ($shortcode, $passkey) {
            if ($request->url() === 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest') {
                $data = $request->data();

                // 1. Verify Timestamp format is exactly 14 digits (YmdHis)
                $timestamp = $data['Timestamp'];
                if (!preg_match('/^\d{14}$/', $timestamp)) {
                    return false;
                }

                // 2. Verify Password decodes to base64(Shortcode . Passkey . Timestamp)
                $expectedPassword = base64_encode($shortcode . $passkey . $timestamp);
                if ($data['Password'] !== $expectedPassword) {
                    return false;
                }

                return $data['BusinessShortCode'] === $shortcode
                    && $data['Amount'] === 151 // rounded from 150.50
                    && $data['PartyA'] === '254712345678'
                    && $data['TransactionType'] === 'CustomerPayBillOnline';
            }
            return true;
        });
    }

    public function test_stk_push_truncates_long_account_reference_and_description()
    {
        Cache::flush();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'mock_token',
                'expires_in' => '3599',
            ], 200),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
            ], 200),
        ]);

        $service = new MpesaService([
            'env' => 'sandbox',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
        ]);

        // Long reference (25 chars) and description (30 chars)
        $service->initiateStkPush([
            'phone_number' => '0712345678',
            'amount' => 100,
            'account_reference' => 'INVOICE-NAIROBI-2026-001',
            'transaction_desc' => 'Very long description of payment',
        ]);

        Http::assertSent(function ($request) {
            if ($request->url() === 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest') {
                $data = $request->data();
                // AccountReference must not exceed 12 chars
                // TransactionDesc must not exceed 13 chars
                return strlen($data['AccountReference']) <= 12
                    && $data['AccountReference'] === 'INVOICE-NAIR'
                    && strlen($data['TransactionDesc']) <= 13
                    && $data['TransactionDesc'] === 'Very long des';
            }
            return true;
        });
    }

    public function test_dispatches_stk_push_with_till_shortcode_type()
    {
        Cache::flush();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'mock_token',
                'expires_in' => '3599',
            ], 200),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => '29115-34620561-2',
                'CheckoutRequestID' => 'ws_CO_191220261025000001',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ], 200),
        ]);

        $service = new MpesaService([
            'env' => 'sandbox',
            'consumer_key' => 'test_key',
            'consumer_secret' => 'test_secret',
            'shortcode' => '543210',
            'passkey' => 'test_passkey',
            'shortcode_type' => 'till',
        ]);

        $result = $service->initiateStkPush([
            'phone_number' => '0712345678',
            'amount' => 500,
        ]);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if ($request->url() === 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest') {
                $data = $request->data();
                return $data['TransactionType'] === 'CustomerBuyGoodsOnline'
                    && $data['BusinessShortCode'] === '543210';
            }
            return true;
        });
    }
}
