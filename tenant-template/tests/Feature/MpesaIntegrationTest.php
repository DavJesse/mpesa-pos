<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Mpesa\Models\Transaction;
use App\Modules\System\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MpesaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $adminUser;
    protected User $tellerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'name' => 'Main Branch',
            'location' => 'Nairobi CBD',
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'level' => 100]);
        $tellerRole = Role::firstOrCreate(['name' => 'teller', 'level' => 10]);

        $this->adminUser = User::create([
            'branch_id' => $this->branch->id,
            'role_id' => $adminRole->id,
            'name' => 'Admin Boss',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->tellerUser = User::create([
            'branch_id' => $this->branch->id,
            'role_id' => $tellerRole->id,
            'name' => 'Teller Jane',
            'email' => 'teller@example.com',
            'password' => bcrypt('secret123'),
        ]);
    }

    // --------------------------------------------------------------------------
    // 1. Authentication & Role-Based Access Control Tests
    // --------------------------------------------------------------------------

    public function test_unauthenticated_requests_are_rejected()
    {
        $this->getJson('/api/v1/admin/settings/mpesa')->assertStatus(401);
        $this->postJson('/api/v1/admin/settings/mpesa', [])->assertStatus(401);
        $this->postJson('/api/v1/mpesa/stkpush', [])->assertStatus(401);
    }

    public function test_non_admin_cannot_update_daraja_settings()
    {
        Sanctum::actingAs($this->tellerUser);

        $response = $this->postJson('/api/v1/admin/settings/mpesa', [
            'consumer_key' => 'sample_key',
            'consumer_secret' => 'sample_secret',
            'shortcode' => '174379',
            'passkey' => 'sample_passkey',
            'env' => 'sandbox',
            'shortcode_type' => 'paybill',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_view_daraja_settings()
    {
        Sanctum::actingAs($this->tellerUser);

        $response = $this->getJson('/api/v1/admin/settings/mpesa');
        $response->assertStatus(403);
    }

    // --------------------------------------------------------------------------
    // 2. Settings Management, Validation & Encryption Tests
    // --------------------------------------------------------------------------

    public function test_admin_settings_get_endpoint_returns_not_configured_when_empty()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/admin/settings/mpesa');

        $response->assertStatus(200)
            ->assertJson([
                'configured' => false,
            ]);
    }

    public function test_admin_settings_update_fails_validation_for_missing_or_invalid_fields()
    {
        Sanctum::actingAs($this->adminUser);

        // Completely empty payload
        $this->postJson('/api/v1/admin/settings/mpesa', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'env', 'shortcode_type']);

        // Invalid env (must be sandbox or production)
        $this->postJson('/api/v1/admin/settings/mpesa', [
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
            'env' => 'staging',
            'shortcode_type' => 'paybill',
        ])->assertStatus(422)->assertJsonValidationErrors(['env']);

        // Invalid shortcode_type (must be paybill or till)
        $this->postJson('/api/v1/admin/settings/mpesa', [
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
            'env' => 'sandbox',
            'shortcode_type' => 'kiosk',
        ])->assertStatus(422)->assertJsonValidationErrors(['shortcode_type']);
    }

    public function test_admin_can_save_settings_and_credentials_are_encrypted_in_raw_db()
    {
        Sanctum::actingAs($this->adminUser);

        $plainConsumerKey = 'ActualPlainConsumerKey12345';
        $plainConsumerSecret = 'ActualPlainConsumerSecret67890';
        $plainPasskey = 'ActualPlainPasskeySuperSecretValue';

        $response = $this->postJson('/api/v1/admin/settings/mpesa', [
            'consumer_key' => $plainConsumerKey,
            'consumer_secret' => $plainConsumerSecret,
            'shortcode' => '174379',
            'passkey' => $plainPasskey,
            'env' => 'sandbox',
            'shortcode_type' => 'paybill',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'shortcode' => '174379',
                    'shortcode_type' => 'paybill',
                    'env' => 'sandbox',
                ],
            ]);

        // CRITICAL ACCEPTANCE CRITERIA #1: Daraja credentials cannot be read in plaintext from raw database dumps
        $rawRecord = SystemSetting::where('key', 'daraja_credentials')->first();
        $this->assertNotNull($rawRecord);

        $rawPayload = is_array($rawRecord->payload) ? $rawRecord->payload : json_decode($rawRecord->payload, true);

        // Verify plaintext DOES NOT appear anywhere in the raw database record
        $this->assertNotEquals($plainConsumerKey, $rawPayload['consumer_key']);
        $this->assertNotEquals($plainConsumerSecret, $rawPayload['consumer_secret']);
        $this->assertNotEquals($plainPasskey, $rawPayload['passkey']);

        $this->assertStringNotContainsString($plainConsumerKey, json_encode($rawPayload));
        $this->assertStringNotContainsString($plainConsumerSecret, json_encode($rawPayload));
        $this->assertStringNotContainsString($plainPasskey, json_encode($rawPayload));

        // Verify that Crypt can cleanly decrypt it
        $this->assertEquals($plainConsumerKey, Crypt::decryptString($rawPayload['consumer_key']));
        $this->assertEquals($plainConsumerSecret, Crypt::decryptString($rawPayload['consumer_secret']));
        $this->assertEquals($plainPasskey, Crypt::decryptString($rawPayload['passkey']));
    }

    public function test_updating_settings_purges_cached_oauth_token()
    {
        Sanctum::actingAs($this->adminUser);

        // Seed a stale token in cache
        Cache::put('daraja:oauth_token:174379', 'stale_token_value', 3300);
        $this->assertTrue(Cache::has('daraja:oauth_token:174379'));

        // Update settings
        $this->postJson('/api/v1/admin/settings/mpesa', [
            'consumer_key' => 'new_key',
            'consumer_secret' => 'new_secret',
            'shortcode' => '174379',
            'passkey' => 'new_passkey',
            'env' => 'sandbox',
            'shortcode_type' => 'paybill',
        ])->assertStatus(200);

        // Cached token must be purged
        $this->assertFalse(Cache::has('daraja:oauth_token:174379'));
    }

    public function test_admin_settings_get_endpoint_masks_secrets()
    {
        Sanctum::actingAs($this->adminUser);

        SystemSetting::create([
            'key' => 'daraja_credentials',
            'payload' => [
                'consumer_key' => Crypt::encryptString('MySuperSecretKeyLongValue'),
                'consumer_secret' => Crypt::encryptString('SecretSecretSecret'),
                'passkey' => Crypt::encryptString('PasskeySecret'),
                'shortcode' => '174379',
                'env' => 'production',
                'shortcode_type' => 'till',
            ],
        ]);

        $response = $this->getJson('/api/v1/admin/settings/mpesa');

        $response->assertStatus(200)
            ->assertJson([
                'configured' => true,
                'data' => [
                    'shortcode' => '174379',
                    'env' => 'production',
                    'shortcode_type' => 'till',
                    'consumer_secret_masked' => '••••••••',
                    'passkey_masked' => '••••••••',
                ],
            ]);

        // Ensure real secret is not returned
        $response->assertDontSee('SecretSecretSecret');
        $response->assertDontSee('PasskeySecret');
    }

    // --------------------------------------------------------------------------
    // 3. STK Push Execution, Validation & Error Handling Tests
    // --------------------------------------------------------------------------

    public function test_stk_push_fails_when_mpesa_is_not_configured()
    {
        Sanctum::actingAs($this->tellerUser);

        // No system_settings exist
        $response = $this->postJson('/api/v1/mpesa/stkpush', [
            'phone_number' => '0712345678',
            'amount' => 100,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonFragment(['message' => 'Daraja M-Pesa is not configured. Please enter credentials in Admin Settings first.']);
    }

    public function test_stk_push_fails_validation_for_invalid_phone_or_amount()
    {
        Sanctum::actingAs($this->tellerUser);

        $this->configureDarajaCredentials();

        // Missing phone and amount
        $this->postJson('/api/v1/mpesa/stkpush', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number', 'amount']);

        // Zero amount
        $this->postJson('/api/v1/mpesa/stkpush', [
            'phone_number' => '0712345678',
            'amount' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // Negative amount
        $this->postJson('/api/v1/mpesa/stkpush', [
            'phone_number' => '0712345678',
            'amount' => -25,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // Malformed phone number
        $this->postJson('/api/v1/mpesa/stkpush', [
            'phone_number' => '07123', // too short
            'amount' => 50,
        ])->assertStatus(422)->assertJsonFragment(['success' => false]);
    }

    public function test_stk_push_endpoint_initiates_request_and_persists_transaction()
    {
        Sanctum::actingAs($this->tellerUser);

        $this->configureDarajaCredentials();

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

        $response = $this->postJson('/api/v1/mpesa/stkpush', [
            'phone_number' => '0712345678',
            'amount' => 250.00,
            'reference' => 'INV-001',
            'description' => 'Grocery Order',
            'contact_name' => 'John Doe',
        ]);

        // CRITICAL ACCEPTANCE CRITERIA #2: Returns MerchantRequestID and CheckoutRequestID
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'merchant_request_id' => '29115-34620561-1',
                    'checkout_request_id' => 'ws_CO_191220261025000000',
                    'response_code' => '0',
                ],
            ]);

        // Verify contact is created with normalized 254 phone number
        $this->assertDatabaseHas('contacts', [
            'phone_number' => '254712345678',
            'name' => 'John Doe',
        ]);

        $contact = Contact::where('phone_number', '254712345678')->first();

        // Verify transaction is recorded and properly linked
        $this->assertDatabaseHas('transactions', [
            'branch_id' => $this->branch->id,
            'user_id' => $this->tellerUser->id,
            'contact_id' => $contact->id,
            'amount' => 250.00,
            'payment_method' => 'mpesa',
            'status' => 'pending',
            'mpesa_merchant_request_id' => '29115-34620561-1',
            'mpesa_checkout_request_id' => 'ws_CO_191220261025000000',
            'mpesa_response_code' => '0',
        ]);
    }

    public function test_stk_push_handles_safaricom_rejection_gracefully()
    {
        Sanctum::actingAs($this->tellerUser);

        $this->configureDarajaCredentials();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'mock_token',
                'expires_in' => '3599',
            ], 200),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => '29115-34620561-3',
                'CheckoutRequestID' => 'ws_CO_191220261025000002',
                'ResponseCode' => '1',
                'ResponseDescription' => 'The initiator information is invalid.',
                'CustomerMessage' => 'The initiator information is invalid.',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/mpesa/stkpush', [
            'phone_number' => '0712345678',
            'amount' => 100.00,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'data' => [
                    'merchant_request_id' => '29115-34620561-3',
                    'checkout_request_id' => 'ws_CO_191220261025000002',
                    'response_code' => '1',
                ],
            ]);

        // Transaction must be recorded as 'failed' with error code and description logged
        $this->assertDatabaseHas('transactions', [
            'amount' => 100.00,
            'status' => 'failed',
            'mpesa_response_code' => '1',
            'mpesa_response_description' => 'The initiator information is invalid.',
        ]);
    }

    public function test_stk_push_handles_safaricom_server_down_gracefully()
    {
        Sanctum::actingAs($this->tellerUser);

        $this->configureDarajaCredentials();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'mock_token',
                'expires_in' => '3599',
            ], 200),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'errorMessage' => 'Internal Server Error',
            ], 500),
        ]);

        $response = $this->postJson('/api/v1/mpesa/stkpush', [
            'phone_number' => '0712345678',
            'amount' => 100.00,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);

        // Transaction should be recorded as failed
        $this->assertDatabaseHas('transactions', [
            'amount' => 100.00,
            'status' => 'failed',
        ]);
    }

    // --------------------------------------------------------------------------
    // Helper Methods
    // --------------------------------------------------------------------------

    protected function configureDarajaCredentials(): void
    {
        SystemSetting::create([
            'key' => 'daraja_credentials',
            'payload' => [
                'consumer_key' => Crypt::encryptString('test_key'),
                'consumer_secret' => Crypt::encryptString('test_secret'),
                'passkey' => Crypt::encryptString('test_passkey'),
                'shortcode' => '174379',
                'env' => 'sandbox',
                'shortcode_type' => 'paybill',
            ],
        ]);
    }
}
