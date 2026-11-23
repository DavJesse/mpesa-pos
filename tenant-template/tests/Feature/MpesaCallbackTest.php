<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Mpesa\Events\TransactionConfirmedEvent;
use App\Modules\Mpesa\Jobs\ProcessMpesaCallbackJob;
use App\Modules\Mpesa\Models\Transaction;
use App\Modules\System\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MpesaCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected string $secretToken = 'sec_token_random_32_chars_12345';
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $tellerA;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = Branch::create([
            'name' => 'Kilimani Branch',
            'location' => 'Nairobi Kilimani',
        ]);

        $this->branchB = Branch::create([
            'name' => 'Westlands Branch',
            'location' => 'Nairobi Westlands',
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'level' => 100]);
        $tellerRole = Role::firstOrCreate(['name' => 'teller', 'level' => 10]);

        $this->tellerA = User::create([
            'branch_id' => $this->branchA->id,
            'role_id' => $tellerRole->id,
            'name' => 'Teller Jane',
            'email' => 'tellerA@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->adminUser = User::create([
            'branch_id' => $this->branchA->id,
            'role_id' => $adminRole->id,
            'name' => 'Admin Boss',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        SystemSetting::create([
            'key' => 'daraja_credentials',
            'payload' => [
                'shortcode' => '174379',
                'env' => 'sandbox',
                'callback_token' => $this->secretToken,
            ],
        ]);
    }

    // --------------------------------------------------------------------------
    // 1. Signature Verification & Handshake Security Tests
    // --------------------------------------------------------------------------

    public function test_rejects_callback_with_invalid_or_missing_token()
    {
        Queue::fake();

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-34620561-1',
                    'CheckoutRequestID' => 'ws_CO_TEST',
                    'ResultCode' => 0,
                ],
            ],
        ];

        // Wrong token must be rejected with 403
        $response = $this->postJson('/api/v1/mpesa/callback/wrong-token-abc', $payload);
        $response->assertStatus(403)
            ->assertJson(['message' => 'Invalid security signature.']);

        // Queue job must not be dispatched
        Queue::assertNothingPushed();
    }

    public function test_callback_endpoint_rejects_when_system_settings_have_no_token()
    {
        Queue::fake();

        // Wipe callback token from system_settings
        SystemSetting::where('key', 'daraja_credentials')->update([
            'payload' => ['shortcode' => '174379'],
        ]);

        $response = $this->postJson("/api/v1/mpesa/callback/{$this->secretToken}", [
            'Body' => ['stkCallback' => ['ResultCode' => 0]],
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Invalid security signature.']);

        Queue::assertNothingPushed();
    }

    public function test_accepts_valid_token_and_dispatches_queued_job_with_sub_second_ack()
    {
        Queue::fake();

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-34620561-1',
                    'CheckoutRequestID' => 'ws_CO_TEST_001',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/mpesa/callback/{$this->secretToken}", $payload);

        // Immediate HTTP 200 acknowledgment to Safaricom handshake
        $response->assertStatus(200)
            ->assertJson([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted',
            ]);

        // Job pushed onto queue
        Queue::assertPushed(ProcessMpesaCallbackJob::class, function ($job) use ($payload) {
            return $job->payload === $payload;
        });
    }

    public function test_callback_works_on_both_v1_and_unversioned_routes()
    {
        Queue::fake();

        $payload = [
            'Body' => ['stkCallback' => ['ResultCode' => 0]],
        ];

        $this->postJson("/api/v1/mpesa/callback/{$this->secretToken}", $payload)
            ->assertStatus(200);

        $this->postJson("/api/mpesa/callback/{$this->secretToken}", $payload)
            ->assertStatus(200);
    }

    // --------------------------------------------------------------------------
    // 2. End-to-End Callback Ledger & Event Tests
    // --------------------------------------------------------------------------

    public function test_end_to_end_callback_updates_transaction_and_broadcasts_websocket()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_E2E_CONFIRMATION_001';

        $contact = Contact::create([
            'name' => 'Peter Omondi',
            'phone_number' => '254700112233',
        ]);

        $transaction = Transaction::create([
            'branch_id' => $this->branchA->id,
            'contact_id' => $contact->id,
            'amount' => 1200.00,
            'payment_method' => 'mpesa',
            'status' => 'pending',
            'mpesa_checkout_request_id' => $checkoutId,
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-9988-1',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1200.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'QLK9OP12TY'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123121500],
                            ['Name' => 'PhoneNumber', 'Value' => 254700112233],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/mpesa/callback/{$this->secretToken}", $payload);
        $response->assertStatus(200);

        // Run the job synchronously to simulate the queue worker
        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        $this->assertEquals('success', $transaction->status);
        $this->assertEquals('QLK9OP12TY', $transaction->mpesa_receipt_number);
        $this->assertEquals('0', $transaction->mpesa_result_code);
        $this->assertNotNull($transaction->paid_at);

        Event::assertDispatched(TransactionConfirmedEvent::class, function ($event) use ($transaction) {
            return $event->transaction->id === $transaction->id
                && $event->transaction->status === 'success'
                && $event->transaction->mpesa_receipt_number === 'QLK9OP12TY';
        });
    }

    public function test_end_to_end_callback_handles_user_cancellation_and_broadcasts_failure()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_E2E_CANCELLED_002';

        $contact = Contact::create([
            'name' => 'Mary Wambui',
            'phone_number' => '254711223344',
        ]);

        $transaction = Transaction::create([
            'branch_id' => $this->branchA->id,
            'contact_id' => $contact->id,
            'amount' => 750.00,
            'payment_method' => 'mpesa',
            'status' => 'pending',
            'mpesa_checkout_request_id' => $checkoutId,
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-9988-2',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/mpesa/callback/{$this->secretToken}", $payload);
        $response->assertStatus(200);

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        $this->assertEquals('failed', $transaction->status);
        $this->assertEquals('1032', $transaction->mpesa_result_code);
        $this->assertEquals('Request cancelled by user', $transaction->mpesa_result_desc);
        $this->assertNull($transaction->mpesa_receipt_number);
        $this->assertNull($transaction->paid_at);

        Event::assertDispatched(TransactionConfirmedEvent::class, function ($event) use ($transaction) {
            return $event->transaction->id === $transaction->id
                && $event->transaction->status === 'failed';
        });
    }

    public function test_duplicate_callbacks_are_handled_idempotently()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_IDEMPOTENCY_003';

        $contact = Contact::create([
            'name' => 'James Mwangi',
            'phone_number' => '254722334455',
        ]);

        $transaction = Transaction::create([
            'branch_id' => $this->branchA->id,
            'contact_id' => $contact->id,
            'amount' => 450.00,
            'payment_method' => 'mpesa',
            'status' => 'pending',
            'mpesa_checkout_request_id' => $checkoutId,
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-9988-3',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 450.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'RC12345678'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123122000],
                            ['Name' => 'PhoneNumber', 'Value' => 254722334455],
                        ],
                    ],
                ],
            ],
        ];

        // First delivery
        (new ProcessMpesaCallbackJob($payload))->handle();
        $transaction->refresh();
        $this->assertEquals('success', $transaction->status);
        $firstPaidAt = $transaction->paid_at;

        // Second delivery (simulating Safaricom network retry)
        (new ProcessMpesaCallbackJob($payload))->handle();
        $transaction->refresh();

        $this->assertEquals('success', $transaction->status);
        $this->assertEquals('RC12345678', $transaction->mpesa_receipt_number);
        $this->assertEquals($firstPaidAt, $transaction->paid_at);
    }

    // --------------------------------------------------------------------------
    // 3. WebSocket Channel Authorization Security Tests
    //
    // NOTE: We test channel-authorization closures directly by resolving them
    // from the broadcaster's registered channels, rather than hitting the
    // /broadcasting/auth HTTP endpoint. The HTTP endpoint relies on the active
    // broadcaster driver to call verifyUserCanAccessChannel(), which
    // NullBroadcaster and LogBroadcaster never do — they always return 200
    // regardless of the channel callback's return value. This helper pulls the
    // closures from channels.php via reflection and calls them, which is
    // driver-agnostic and reliable across all environments.
    // --------------------------------------------------------------------------

    /**
     * Resolve a registered broadcast channel callback and invoke it.
     *
     * Looks up the closure registered via Broadcast::channel() in routes/channels.php,
     * matches the channel name against registered patterns, and calls the closure
     * with the given user and extracted parameters.
     */
    private function checkChannelAuth(User $user, string $channelName): bool
    {
        $broadcaster = app(\Illuminate\Broadcasting\BroadcastManager::class)->driver();

        // Access the protected $channels property via reflection
        $ref = new \ReflectionProperty($broadcaster, 'channels');
        $ref->setAccessible(true);
        $channels = $ref->getValue($broadcaster);

        foreach ($channels as $pattern => $callback) {
            // Convert channel pattern like "branches.{branchId}" to regex
            $regex = preg_replace('/\{[^}]+\}/', '([^.]+)', $pattern);

            if (preg_match('#^' . $regex . '$#', $channelName, $matches)) {
                array_shift($matches); // Remove full match, keep captured params
                return (bool) $callback($user, ...$matches);
            }
        }

        return false;
    }

    public function test_teller_can_only_authorize_their_own_branch_websocket_channel()
    {
        // Teller A belongs to branchA — allowed on own branch channel
        $this->assertTrue(
            $this->checkChannelAuth($this->tellerA, 'branches.' . $this->branchA->id),
            'Teller should be authorized for their own branch channel'
        );

        // Teller A must be denied access to branchB's channel
        $this->assertFalse(
            $this->checkChannelAuth($this->tellerA, 'branches.' . $this->branchB->id),
            'Teller must not be authorized for a different branch channel'
        );
    }

    public function test_admin_can_authorize_any_branch_websocket_channel()
    {
        $this->assertTrue(
            $this->checkChannelAuth($this->adminUser, 'branches.' . $this->branchA->id),
            'Admin should be authorized for branchA channel'
        );

        $this->assertTrue(
            $this->checkChannelAuth($this->adminUser, 'branches.' . $this->branchB->id),
            'Admin should be authorized for branchB channel'
        );
    }

    public function test_teller_can_authorize_own_user_channel_but_not_another()
    {
        $this->assertTrue(
            $this->checkChannelAuth($this->tellerA, 'users.' . $this->tellerA->id),
            'Teller should be authorized for their own user channel'
        );

        $this->assertFalse(
            $this->checkChannelAuth($this->tellerA, 'users.' . $this->adminUser->id),
            'Teller must not be authorized for another user\'s channel'
        );
    }

    public function test_admin_can_authorize_any_user_channel()
    {
        $this->assertTrue(
            $this->checkChannelAuth($this->adminUser, 'users.' . $this->tellerA->id),
            'Admin should be authorized for any user channel'
        );
    }

    public function test_teller_can_authorize_transaction_in_own_branch()
    {
        $contact = Contact::create([
            'name'         => 'Auth Test Customer',
            'phone_number' => '254700000001',
        ]);

        $transaction = Transaction::create([
            'branch_id'                  => $this->branchA->id,
            'contact_id'                 => $contact->id,
            'amount'                     => 100.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => 'ws_CO_CHAN_AUTH_001',
        ]);

        $this->assertTrue(
            $this->checkChannelAuth($this->tellerA, 'transactions.' . $transaction->id),
            'Teller should be authorized for a transaction in their own branch'
        );
    }

    public function test_teller_cannot_authorize_transaction_in_another_branch()
    {
        $contact = Contact::create([
            'name'         => 'Other Branch Customer',
            'phone_number' => '254700000002',
        ]);

        $transaction = Transaction::create([
            'branch_id'                  => $this->branchB->id,
            'contact_id'                 => $contact->id,
            'amount'                     => 200.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => 'ws_CO_CHAN_AUTH_002',
        ]);

        $this->assertFalse(
            $this->checkChannelAuth($this->tellerA, 'transactions.' . $transaction->id),
            'Teller must not be authorized for a transaction in a different branch'
        );
    }

    public function test_admin_can_authorize_any_transaction_channel()
    {
        $contact = Contact::create([
            'name'         => 'Admin Access Customer',
            'phone_number' => '254700000003',
        ]);

        $transaction = Transaction::create([
            'branch_id'                  => $this->branchB->id,
            'contact_id'                 => $contact->id,
            'amount'                     => 500.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => 'ws_CO_CHAN_AUTH_003',
        ]);

        $this->assertTrue(
            $this->checkChannelAuth($this->adminUser, 'transactions.' . $transaction->id),
            'Admin should be authorized for any transaction channel regardless of branch'
        );
    }
}
