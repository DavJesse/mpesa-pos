<?php

namespace App\Modules\Mpesa\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Mpesa\Models\Transaction;
use App\Modules\Mpesa\Services\MpesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MpesaStkController extends Controller
{
    protected MpesaService $mpesaService;

    public function __construct(MpesaService $mpesaService)
    {
        $this->mpesaService = $mpesaService;
    }

    /**
     * Initiate Lipa Na M-Pesa Online (LNMO) STK Push.
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:1'],
            'reference' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'uuid'],
            'contact_name' => ['nullable', 'string', 'max:50'],
        ]);

        if (!$this->mpesaService->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Daraja M-Pesa is not configured. Please enter credentials in Admin Settings first.',
            ], 422);
        }

        try {
            $normalizedPhone = MpesaService::normalizePhoneNumber($validated['phone_number']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Determine branch_id (from request or authenticated user's branch)
        $user = $request->user();
        $branchId = $validated['branch_id'] ?? $user?->branch_id;

        // If no branch provided, resolve first branch or allow nullable
        if (empty($branchId)) {
            $branchId = \App\Modules\Branches\Models\Branch::value('id');
        }

        // Resolve or create contact record
        $contact = Contact::withoutGlobalScopes()->firstOrCreate(
            ['phone_number' => $normalizedPhone],
            ['name' => $validated['contact_name'] ?? 'Customer ' . substr($normalizedPhone, -4)]
        );

        $reference = $validated['reference'] ?? 'PAY' . strtoupper(substr(uniqid(), -6));
        $description = $validated['description'] ?? 'Payment';

        try {
            $response = $this->mpesaService->initiateStkPush([
                'phone_number' => $normalizedPhone,
                'amount' => $validated['amount'],
                'account_reference' => $reference,
                'transaction_desc' => $description,
            ]);
        } catch (\Throwable $e) {
            Log::error('STK Push dispatch failed', [
                'error' => $e->getMessage(),
                'phone' => $normalizedPhone,
                'amount' => $validated['amount'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not initiate STK push: ' . $e->getMessage(),
            ], 500);
        }

        // Save transaction log to database
        $transaction = Transaction::create([
            'branch_id' => $branchId,
            'user_id' => $user?->id,
            'contact_id' => $contact->id,
            'amount' => $validated['amount'],
            'payment_method' => 'mpesa',
            'status' => $response['success'] ? 'pending' : 'failed',
            'mpesa_merchant_request_id' => $response['merchant_request_id'],
            'mpesa_checkout_request_id' => $response['checkout_request_id'],
            'mpesa_response_code' => $response['response_code'],
            'mpesa_response_description' => $response['response_description'],
            'mpesa_customer_message' => $response['customer_message'],
        ]);

        $statusCode = $response['success'] ? 200 : 400;

        return response()->json([
            'success' => $response['success'],
            'message' => $response['customer_message'] ?? $response['response_description'] ?? 'STK push dispatched.',
            'data' => [
                'transaction_id' => $transaction->id,
                'merchant_request_id' => $response['merchant_request_id'],
                'checkout_request_id' => $response['checkout_request_id'],
                'response_code' => $response['response_code'],
                'response_description' => $response['response_description'],
            ],
        ], $statusCode);
    }
}

