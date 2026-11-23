<?php

namespace Tests\Unit;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Mpesa\Events\TransactionConfirmedEvent;
use App\Modules\Mpesa\Jobs\ProcessMpesaCallbackJob;
use App\Modules\Mpesa\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProcessMpesaCallbackJobTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $teller;
    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'name' => 'Westlands Branch',
            'location' => 'Nairobi Westlands',
        ]);

        $role = Role::firstOrCreate(['name' => 'teller', 'level' => 10]);

        $this->teller = User::create([
            'branch_id' => $this->branch->id,
            'role_id' => $role->id,
            'name' => 'Teller John',
            'email' => 'john@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->contact = Contact::create([
            'name' => 'Alice Maina',
            'phone_number' => '254712345678',
        ]);
    }

    public function test_handles_successful_mpesa_callback_and_updates_ledger()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_SUCCESS_TEST_001';

        $transaction = Transaction::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->teller->id,
            'contact_id' => $this->contact->id,
            'amount' => 150.00,
            'payment_method' => 'mpesa',
            'status' => 'pending',
            'mpesa_checkout_request_id' => $checkoutId,
            'mpesa_merchant_request_id' => '29115-34620561-1',
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-34620561-1',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 150.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123114530],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        $this->assertEquals('success', $transaction->status);
        $this->assertEquals('NLJ7RT61SV', $transaction->mpesa_receipt_number);
        $this->assertEquals('0', $transaction->mpesa_result_code);
        $this->assertEquals('The service request is processed successfully.', $transaction->mpesa_result_desc);
        $this->assertNotNull($transaction->paid_at);
        $this->assertEquals('2026-11-23 11:45:30', $transaction->paid_at->format('Y-m-d H:i:s'));

        // Assert broadcast event fired
        Event::assertDispatched(TransactionConfirmedEvent::class, function ($event) use ($transaction) {
            $channels = $event->broadcastOn();
            $channelNames = array_map(fn($c) => $c->name, $channels);

            return $event->transaction->id === $transaction->id
                && in_array('private-branches.' . $this->branch->id, $channelNames)
                && in_array('private-transactions.' . $transaction->id, $channelNames)
                && in_array('private-users.' . $this->teller->id, $channelNames);
        });
    }

    public function test_handles_cancelled_or_timeout_callback_gracefully()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_CANCELLED_TEST_002';

        $transaction = Transaction::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->teller->id,
            'contact_id' => $this->contact->id,
            'amount' => 500.00,
            'payment_method' => 'mpesa',
            'status' => 'pending',
            'mpesa_checkout_request_id' => $checkoutId,
            'mpesa_merchant_request_id' => '29115-34620561-2',
        ]);

        // In cancelled/timeout payloads, CallbackMetadata is completely omitted by Safaricom
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-34620561-2',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ];

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

    public function test_auto_creates_contact_if_phone_does_not_exist()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_NEW_CONTACT_TEST_003';
        $newPhone = '0799887766';
        $expectedNormalized = '254799887766';

        $initialContact = Contact::create([
            'name' => 'Initial Contact',
            'phone_number' => '254700000000',
        ]);

        $transaction = Transaction::create([
            'branch_id' => $this->branch->id,
            'contact_id' => $initialContact->id,
            'amount' => 300.00,
            'payment_method' => 'mpesa',
            'status' => 'pending',
            'mpesa_checkout_request_id' => $checkoutId,
        ]);

        $this->assertDatabaseMissing('contacts', ['phone_number' => $expectedNormalized]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 300.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61AA'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123120000],
                            ['Name' => 'PhoneNumber', 'Value' => $newPhone],
                        ],
                    ],
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        // 1. Assert contact was created with normalized phone
        $this->assertDatabaseHas('contacts', ['phone_number' => $expectedNormalized]);
        $createdContact = Contact::where('phone_number', $expectedNormalized)->first();

        // 2. Assert transaction contact was updated
        $transaction->refresh();
        $this->assertEquals($createdContact->id, $transaction->contact_id);
    }

    public function test_handles_unknown_transaction_gracefully()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_NON_EXISTENT_ID',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        // Job completes without exception and dispatches no event
        Event::assertNotDispatched(TransactionConfirmedEvent::class);
    }

    // --------------------------------------------------------------------------
    // 5. MerchantRequestID Fallback Lookup
    // --------------------------------------------------------------------------

    public function test_falls_back_to_merchant_request_id_when_checkout_id_is_missing()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $merchantId = 'MR_FALLBACK_TEST_005';

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 200.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_merchant_request_id'  => $merchantId,
            'mpesa_checkout_request_id'  => 'ws_CO_ORIGINAL_005',
        ]);

        // Payload has no CheckoutRequestID — only MerchantRequestID
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $merchantId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'Success',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 200.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MRFB12345X'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123130000],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        $this->assertEquals('success', $transaction->status);
        $this->assertEquals('MRFB12345X', $transaction->mpesa_receipt_number);

        Event::assertDispatched(TransactionConfirmedEvent::class);
    }

    // --------------------------------------------------------------------------
    // 6. TransactionDate Parsing Edge Cases
    // --------------------------------------------------------------------------

    public function test_falls_back_to_now_when_transaction_date_is_missing()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_NO_DATE_006';

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 100.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        // TransactionDate is intentionally omitted from metadata
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'Success',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 100.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NODATE0001'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        $before = now()->subSecond();
        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();
        $after = now()->addSecond();

        $transaction->refresh();

        $this->assertEquals('success', $transaction->status);
        $this->assertNotNull($transaction->paid_at);
        // paid_at should fall back to approximately now() (tolerance for DB second-truncation)
        $this->assertTrue(
            $transaction->paid_at->between($before, $after),
            'paid_at should fall back to now() when TransactionDate is missing'
        );
    }

    public function test_falls_back_to_now_when_transaction_date_is_malformed()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_BAD_DATE_007';

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 100.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        // Malformed date value that Carbon::createFromFormat('YmdHis') cannot parse
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'Success',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 100.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'BADDATE001'],
                            ['Name' => 'TransactionDate', 'Value' => 'not-a-date'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        $before = now()->subSecond();
        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();
        $after = now()->addSecond();

        $transaction->refresh();

        $this->assertEquals('success', $transaction->status);
        $this->assertNotNull($transaction->paid_at);
        $this->assertTrue(
            $transaction->paid_at->between($before, $after),
            'paid_at should fall back to now() when TransactionDate is unparseable'
        );
    }

    // --------------------------------------------------------------------------
    // 7. Existing Contact Reuse (No Duplicates)
    // --------------------------------------------------------------------------

    public function test_links_existing_contact_instead_of_creating_duplicate()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_EXISTING_CONTACT_008';

        // This contact already exists with the same phone number Safaricom will return
        $existingContact = Contact::create([
            'name'         => 'Grace Wanjiku',
            'phone_number' => '254722999888',
        ]);

        // Create a transaction initially pointing to a different placeholder contact
        $placeholderContact = Contact::create([
            'name'         => 'Unknown',
            'phone_number' => '254700000000',
        ]);

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $placeholderContact->id,
            'amount'                     => 400.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        $contactCountBefore = Contact::count();

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'Success',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 400.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'EXIST00008'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123140000],
                            ['Name' => 'PhoneNumber', 'Value' => 254722999888],
                        ],
                    ],
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        // No new contact was created
        $this->assertEquals($contactCountBefore, Contact::count());
        // Transaction now points to the existing contact
        $this->assertEquals($existingContact->id, $transaction->contact_id);
    }

    // --------------------------------------------------------------------------
    // 8. Contact Creation Failure Doesn't Crash Transaction
    // --------------------------------------------------------------------------

    public function test_transaction_still_saves_when_contact_resolution_fails()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_BAD_PHONE_009';

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 250.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        $originalContactId = $transaction->contact_id;

        // Phone "999" will throw InvalidArgumentException from normalizePhoneNumber
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'Success',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 250.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'BADPH00009'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123150000],
                            ['Name' => 'PhoneNumber', 'Value' => '999'],
                        ],
                    ],
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        // Transaction was still marked as success despite contact failure
        $this->assertEquals('success', $transaction->status);
        $this->assertEquals('BADPH00009', $transaction->mpesa_receipt_number);
        // contact_id remains unchanged
        $this->assertEquals($originalContactId, $transaction->contact_id);

        Event::assertDispatched(TransactionConfirmedEvent::class);
    }

    // --------------------------------------------------------------------------
    // 9. Missing CallbackMetadata on Success
    // --------------------------------------------------------------------------

    public function test_handles_success_with_missing_callback_metadata()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_NO_META_010';

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 350.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        // ResultCode 0 but no CallbackMetadata at all
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'Success',
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        $this->assertEquals('success', $transaction->status);
        $this->assertEquals('0', $transaction->mpesa_result_code);
        $this->assertNull($transaction->mpesa_receipt_number);
        $this->assertNotNull($transaction->paid_at); // falls back to now()

        Event::assertDispatched(TransactionConfirmedEvent::class);
    }

    // --------------------------------------------------------------------------
    // 10. Event Broadcast Payload Shape
    // --------------------------------------------------------------------------

    public function test_broadcast_event_payload_contains_expected_fields()
    {
        $checkoutId = 'ws_CO_PAYLOAD_011';

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 600.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'Success',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 600.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'PAYLOAD011'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123160000],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        $event = new TransactionConfirmedEvent($transaction);
        $broadcastData = $event->broadcastWith();

        // Assert every expected field is present
        $this->assertArrayHasKey('id', $broadcastData);
        $this->assertArrayHasKey('branch_id', $broadcastData);
        $this->assertArrayHasKey('user_id', $broadcastData);
        $this->assertArrayHasKey('contact_id', $broadcastData);
        $this->assertArrayHasKey('amount', $broadcastData);
        $this->assertArrayHasKey('payment_method', $broadcastData);
        $this->assertArrayHasKey('status', $broadcastData);
        $this->assertArrayHasKey('mpesa_receipt_number', $broadcastData);
        $this->assertArrayHasKey('mpesa_result_code', $broadcastData);
        $this->assertArrayHasKey('mpesa_result_desc', $broadcastData);
        $this->assertArrayHasKey('paid_at', $broadcastData);
        $this->assertArrayHasKey('updated_at', $broadcastData);

        // Assert values are correct
        $this->assertEquals($transaction->id, $broadcastData['id']);
        $this->assertEquals(600.00, $broadcastData['amount']);
        $this->assertEquals('success', $broadcastData['status']);
        $this->assertEquals('PAYLOAD011', $broadcastData['mpesa_receipt_number']);
        $this->assertEquals('transaction.confirmed', $event->broadcastAs());
    }

    // --------------------------------------------------------------------------
    // 11. Event Broadcast Channels When user_id Is Null
    // --------------------------------------------------------------------------

    public function test_broadcast_omits_user_channel_when_user_id_is_null()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_NO_USER_012';

        // Transaction without user_id (e.g. API-initiated, no teller assigned)
        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 100.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'Success',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 100.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NOUSER0012'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123160000],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        Event::assertDispatched(TransactionConfirmedEvent::class, function ($event) use ($transaction) {
            $channels = $event->broadcastOn();
            $channelNames = array_map(fn($c) => $c->name, $channels);

            // Should have branch + transaction channels, but NOT user channel
            return count($channels) === 2
                && in_array('private-branches.' . $this->branch->id, $channelNames)
                && in_array('private-transactions.' . $transaction->id, $channelNames)
                && !collect($channelNames)->contains(fn($name) => str_starts_with($name, 'private-users.'));
        });
    }

    // --------------------------------------------------------------------------
    // 12. Alternative Payload Structures
    // --------------------------------------------------------------------------

    public function test_handles_flat_stk_callback_payload_format()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_FLAT_013';

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 100.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        // Payload uses top-level "stkCallback" key (no "Body" wrapper)
        $payload = [
            'stkCallback' => [
                'CheckoutRequestID' => $checkoutId,
                'ResultCode'        => 0,
                'ResultDesc'        => 'Success',
                'CallbackMetadata'  => [
                    'Item' => [
                        ['Name' => 'Amount', 'Value' => 100.00],
                        ['Name' => 'MpesaReceiptNumber', 'Value' => 'FLATFMT013'],
                        ['Name' => 'TransactionDate', 'Value' => 20261123160000],
                        ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                    ],
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        $this->assertEquals('success', $transaction->status);
        $this->assertEquals('FLATFMT013', $transaction->mpesa_receipt_number);
    }

    // --------------------------------------------------------------------------
    // 13. Various Safaricom Failure ResultCodes
    // --------------------------------------------------------------------------

    /**
     * @dataProvider safaricomFailureCodesProvider
     */
    public function test_handles_various_safaricom_failure_codes(int $resultCode, string $resultDesc)
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_FAIL_CODE_' . $resultCode;

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 100.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => $resultCode,
                    'ResultDesc'        => $resultDesc,
                ],
            ],
        ];

        $job = new ProcessMpesaCallbackJob($payload);
        $job->handle();

        $transaction->refresh();

        $this->assertEquals('failed', $transaction->status);
        $this->assertEquals((string) $resultCode, $transaction->mpesa_result_code);
        $this->assertEquals($resultDesc, $transaction->mpesa_result_desc);
        $this->assertNull($transaction->mpesa_receipt_number);
        $this->assertNull($transaction->paid_at);

        Event::assertDispatched(TransactionConfirmedEvent::class, function ($event) use ($transaction) {
            return $event->transaction->id === $transaction->id
                && $event->transaction->status === 'failed';
        });
    }

    public static function safaricomFailureCodesProvider(): array
    {
        return [
            'User cancelled'               => [1032, 'Request cancelled by user'],
            'STK push timeout'             => [1037, 'DS timeout user cannot be reached'],
            'Generic failure'              => [1,    'The balance is insufficient for the transaction'],
            'Service unavailable'          => [2001, 'The initiator information is invalid'],
            'Duplicate request'            => [1, 'Duplicate request'],
        ];
    }

    // --------------------------------------------------------------------------
    // 14. Idempotency — Reprocessing Already-Completed Transaction
    // --------------------------------------------------------------------------

    public function test_reprocessing_already_successful_transaction_overwrites_idempotently()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_IDEMPOTENT_014';

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 500.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'Success',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 500.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'IDEMP00014'],
                            ['Name' => 'TransactionDate', 'Value' => 20261123140000],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        // First run
        (new ProcessMpesaCallbackJob($payload))->handle();
        $transaction->refresh();
        $this->assertEquals('success', $transaction->status);
        $firstPaidAt = $transaction->paid_at->toIso8601String();
        $firstReceiptNumber = $transaction->mpesa_receipt_number;

        // Second run (Safaricom retry / duplicate delivery)
        (new ProcessMpesaCallbackJob($payload))->handle();
        $transaction->refresh();

        // Status remains success, fields are unchanged
        $this->assertEquals('success', $transaction->status);
        $this->assertEquals($firstReceiptNumber, $transaction->mpesa_receipt_number);
        $this->assertEquals('0', $transaction->mpesa_result_code);
    }

    public function test_reprocessing_already_failed_transaction_keeps_failed_status()
    {
        Event::fake([TransactionConfirmedEvent::class]);

        $checkoutId = 'ws_CO_FAIL_IDEM_015';

        $transaction = Transaction::create([
            'branch_id'                  => $this->branch->id,
            'user_id'                    => $this->teller->id,
            'contact_id'                 => $this->contact->id,
            'amount'                     => 100.00,
            'payment_method'             => 'mpesa',
            'status'                     => 'pending',
            'mpesa_checkout_request_id'  => $checkoutId,
        ]);

        $failPayload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => 1032,
                    'ResultDesc'        => 'Request cancelled by user',
                ],
            ],
        ];

        // First run → failed
        (new ProcessMpesaCallbackJob($failPayload))->handle();
        $transaction->refresh();
        $this->assertEquals('failed', $transaction->status);

        // Second run with same payload
        (new ProcessMpesaCallbackJob($failPayload))->handle();
        $transaction->refresh();

        $this->assertEquals('failed', $transaction->status);
        $this->assertEquals('1032', $transaction->mpesa_result_code);
    }
}
