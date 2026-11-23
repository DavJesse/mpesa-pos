<?php

namespace App\Modules\Mpesa\Jobs;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Mpesa\Events\TransactionConfirmedEvent;
use App\Modules\Mpesa\Models\Transaction;
use App\Modules\Mpesa\Services\MpesaService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMpesaCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $payload;

    /**
     * Create a new job instance.
     *
     * @param array $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $stkCallback = $this->payload['Body']['stkCallback']
            ?? $this->payload['stkCallback']
            ?? $this->payload;

        $merchantRequestId = $stkCallback['MerchantRequestID'] ?? null;
        $checkoutRequestId = $stkCallback['CheckoutRequestID'] ?? null;
        $resultCode = isset($stkCallback['ResultCode']) ? (int) $stkCallback['ResultCode'] : -1;
        $resultDesc = (string) ($stkCallback['ResultDesc'] ?? 'Callback received');

        Log::info('Processing M-Pesa Callback Job', [
            'merchant_request_id' => $merchantRequestId,
            'checkout_request_id' => $checkoutRequestId,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
        ]);

        // Find the transaction by CheckoutRequestID (or fallback to MerchantRequestID)
        $transaction = null;
        if (!empty($checkoutRequestId)) {
            $transaction = Transaction::where('mpesa_checkout_request_id', $checkoutRequestId)->first();
        }
        if (!$transaction && !empty($merchantRequestId)) {
            $transaction = Transaction::where('mpesa_merchant_request_id', $merchantRequestId)->first();
        }

        if (!$transaction) {
            Log::warning('M-Pesa Callback received for unknown transaction', [
                'merchant_request_id' => $merchantRequestId,
                'checkout_request_id' => $checkoutRequestId,
            ]);
            return;
        }

        // Process successful payment (ResultCode == 0)
        if ($resultCode === 0) {
            $metadataItems = $stkCallback['CallbackMetadata']['Item'] ?? [];
            $parsedMeta = [];
            foreach ($metadataItems as $item) {
                if (isset($item['Name'])) {
                    $parsedMeta[$item['Name']] = $item['Value'] ?? null;
                }
            }

            $receiptNumber = $parsedMeta['MpesaReceiptNumber'] ?? null;
            $rawDate = $parsedMeta['TransactionDate'] ?? null;
            $rawPhone = $parsedMeta['PhoneNumber'] ?? null;

            $paidAt = null;
            if (!empty($rawDate)) {
                try {
                    $paidAt = Carbon::createFromFormat('YmdHis', (string) $rawDate);
                } catch (\Throwable $e) {
                    $paidAt = now();
                }
            } else {
                $paidAt = now();
            }

            // Sync or create Contact record if phone number is present
            if (!empty($rawPhone)) {
                try {
                    $normalizedPhone = MpesaService::normalizePhoneNumber((string) $rawPhone);
                    $contact = Contact::firstOrCreate(
                        ['phone_number' => $normalizedPhone],
                        ['name' => 'Customer ' . substr($normalizedPhone, -4)]
                    );

                    $transaction->contact_id = $contact->id;
                } catch (\Throwable $e) {
                    Log::warning('Could not resolve or create contact from callback phone: ' . $e->getMessage());
                }
            }

            $transaction->status = 'success';
            $transaction->mpesa_receipt_number = $receiptNumber;
            $transaction->mpesa_result_code = '0';
            $transaction->mpesa_result_desc = $resultDesc;
            $transaction->paid_at = $paidAt;
            $transaction->save();

            Log::info('Transaction marked SUCCESS', [
                'transaction_id' => $transaction->id,
                'receipt' => $receiptNumber,
            ]);
        } else {
            // Process cancelled / failed transaction (ResultCode != 0)
            $transaction->status = 'failed';
            $transaction->mpesa_result_code = (string) $resultCode;
            $transaction->mpesa_result_desc = $resultDesc;
            $transaction->save();

            Log::info('Transaction marked FAILED', [
                'transaction_id' => $transaction->id,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
            ]);
        }

        // Broadcast real-time WebSocket event to tellers and managers
        event(new TransactionConfirmedEvent($transaction->fresh()));
    }
}
