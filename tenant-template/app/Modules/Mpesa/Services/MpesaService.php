<?php

namespace App\Modules\Mpesa\Services;

use App\Modules\System\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class MpesaService
{
    public const SANDBOX_URL = 'https://sandbox.safaricom.co.ke';
    public const PRODUCTION_URL = 'https://api.safaricom.co.ke';

    public const SHORTCODE_TYPE_PAYBILL = 'paybill';
    public const SHORTCODE_TYPE_TILL = 'till';

    public const TX_TYPE_PAYBILL = 'CustomerPayBillOnline';
    public const TX_TYPE_BUYGOODS = 'CustomerBuyGoodsOnline';

    /**
     * Stored or injected settings.
     */
    protected ?array $settings = null;

    /**
     * Optional settings override (useful for testing or multi-credential scenarios).
     */
    public function __construct(?array $customSettings = null)
    {
        $this->settings = $customSettings;
    }

    /**
     * Check if Daraja credentials have been configured.
     */
    public function isConfigured(): bool
    {
        $settings = $this->getSettings();
        return !empty($settings['consumer_key'])
            && !empty($settings['consumer_secret'])
            && !empty($settings['shortcode'])
            && !empty($settings['passkey']);
    }

    /**
     * Resolve and decrypt stored settings from system_settings table.
     */
    public function getSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $record = SystemSetting::where('key', 'daraja_credentials')->first();
        if (!$record || empty($record->payload)) {
            return [];
        }

        $payload = is_array($record->payload) ? $record->payload : json_decode($record->payload, true);

        $consumerKey = null;
        $consumerSecret = null;
        $passkey = null;

        try {
            $consumerKey = !empty($payload['consumer_key']) ? Crypt::decryptString($payload['consumer_key']) : null;
        } catch (\Throwable $e) {
            Log::error('Failed to decrypt Daraja consumer_key: ' . $e->getMessage());
        }

        try {
            $consumerSecret = !empty($payload['consumer_secret']) ? Crypt::decryptString($payload['consumer_secret']) : null;
        } catch (\Throwable $e) {
            Log::error('Failed to decrypt Daraja consumer_secret: ' . $e->getMessage());
        }

        try {
            $passkey = !empty($payload['passkey']) ? Crypt::decryptString($payload['passkey']) : null;
        } catch (\Throwable $e) {
            Log::error('Failed to decrypt Daraja passkey: ' . $e->getMessage());
        }

        return [
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret,
            'shortcode' => $payload['shortcode'] ?? null,
            'passkey' => $passkey,
            'env' => $payload['env'] ?? 'sandbox',
            'shortcode_type' => $payload['shortcode_type'] ?? self::SHORTCODE_TYPE_PAYBILL,
            'callback_url' => $payload['callback_url'] ?? null,
        ];
    }

    /**
     * Resolve the base URL according to configured environment ('sandbox' vs 'production').
     */
    public function getBaseUrl(): string
    {
        $settings = $this->getSettings();
        $env = strtolower($settings['env'] ?? 'sandbox');

        return $env === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }

    /**
     * Standardize any Kenyan phone number format into 254XXXXXXXXX.
     *
     * Supports:
     * - 07XXXXXXXX -> 2547XXXXXXXX
     * - +2547XXXXXXXX -> 2547XXXXXXXX
     * - 2547XXXXXXXX -> 2547XXXXXXXX
     * - 7XXXXXXXX -> 2547XXXXXXXX
     * - 011XXXXXXX -> 25411XXXXXXX
     * - +25411XXXXXXX -> 25411XXXXXXX
     * - 11XXXXXXX -> 25411XXXXXXX
     *
     * @throws InvalidArgumentException
     */
    public static function normalizePhoneNumber(string $phoneNumber): string
    {
        // Strip out non-digit characters (+, spaces, hyphens, etc.)
        $cleaned = preg_replace('/\D+/', '', $phoneNumber);

        if (empty($cleaned)) {
            throw new InvalidArgumentException('Phone number cannot be empty.');
        }

        // Local 10-digit format starting with 0: e.g. 0712345678 or 0112345678
        if (strlen($cleaned) === 10 && str_starts_with($cleaned, '0')) {
            $cleaned = '254' . substr($cleaned, 1);
        }
        // 9-digit format missing leading 0 or country code: e.g. 712345678 or 112345678
        elseif (strlen($cleaned) === 9 && (str_starts_with($cleaned, '7') || str_starts_with($cleaned, '1'))) {
            $cleaned = '254' . $cleaned;
        }

        // Strict validation: must be 12 digits, start with 254, followed by 7 or 1 and 8 digits
        if (!preg_match('/^254[17]\d{8}$/', $cleaned)) {
            throw new InvalidArgumentException(
                "Invalid Kenyan phone number [{$phoneNumber}]. Expected format 2547XXXXXXXX or 2541XXXXXXXX."
            );
        }

        return $cleaned;
    }

    /**
     * Generate or retrieve cached OAuth access token from Redis.
     *
     * @throws RuntimeException
     */
    public function getAccessToken(?string $shortcode = null): string
    {
        $settings = $this->getSettings();
        $sc = $shortcode ?: ($settings['shortcode'] ?? 'default');
        $cacheKey = "daraja:oauth_token:{$sc}";

        // Check cache (using configured cache store / Redis)
        try {
            $cachedToken = Cache::get($cacheKey);
            if (!empty($cachedToken)) {
                return $cachedToken;
            }
        } catch (\Throwable $e) {
            Log::warning('Redis cache lookup failed for M-Pesa token: ' . $e->getMessage());
        }

        if (empty($settings['consumer_key']) || empty($settings['consumer_secret'])) {
            throw new RuntimeException('Daraja credentials (consumer_key / consumer_secret) are not configured.');
        }

        $url = $this->getBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials';

        $response = Http::withBasicAuth($settings['consumer_key'], $settings['consumer_secret'])
            ->acceptJson()
            ->get($url);

        if (!$response->successful()) {
            Log::error('Daraja OAuth token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'url' => $url,
            ]);
            throw new RuntimeException('Failed to retrieve Daraja access token: ' . $response->body());
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3599);

        if (empty($token)) {
            throw new RuntimeException('Daraja OAuth response missing access_token.');
        }

        // Cache for (expires_in - 300) seconds (~55 mins) to prevent race conditions at expiry boundary
        $ttl = max(60, $expiresIn - 300);

        try {
            Cache::put($cacheKey, $token, $ttl);
        } catch (\Throwable $e) {
            Log::warning('Redis caching of M-Pesa token failed: ' . $e->getMessage());
        }

        return $token;
    }

    /**
     * Clear cached OAuth token for a given shortcode or all.
     */
    public function clearTokenCache(?string $shortcode = null): void
    {
        $settings = $this->getSettings();
        $sc = $shortcode ?: ($settings['shortcode'] ?? 'default');
        $cacheKey = "daraja:oauth_token:{$sc}";

        try {
            Cache::forget($cacheKey);
        } catch (\Throwable $e) {
            Log::warning('Failed to purge M-Pesa token cache: ' . $e->getMessage());
        }
    }

    /**
     * Initiate Lipa Na M-Pesa Online (LNMO) STK Push.
     *
     * @param array $params [
     *   'phone_number' => string (required),
     *   'amount' => int|float (required),
     *   'account_reference' => string (optional, max 12 chars),
     *   'transaction_desc' => string (optional, max 13 chars),
     *   'shortcode' => string (optional override),
     *   'shortcode_type' => string (optional override 'paybill'|'till'),
     *   'callback_url' => string (optional override),
     * ]
     * @return array
     * @throws InvalidArgumentException|RuntimeException
     */
    public function initiateStkPush(array $params): array
    {
        $settings = $this->getSettings();

        if (!$this->isConfigured()) {
            throw new RuntimeException('Daraja API credentials are not configured.');
        }

        $rawPhone = $params['phone_number'] ?? '';
        $normalizedPhone = self::normalizePhoneNumber($rawPhone);

        $amount = (float) ($params['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Transaction amount must be greater than zero.');
        }
        $amountInt = (int) round($amount);

        $shortcode = $params['shortcode'] ?? $settings['shortcode'];
        $passkey = $settings['passkey'];
        $shortcodeType = $params['shortcode_type'] ?? $settings['shortcode_type'] ?? self::SHORTCODE_TYPE_PAYBILL;

        $transactionType = ($shortcodeType === self::SHORTCODE_TYPE_TILL)
            ? self::TX_TYPE_BUYGOODS
            : self::TX_TYPE_PAYBILL;

        $timestamp = date('YmdHis');
        $password = base64_encode($shortcode . $passkey . $timestamp);

        $callbackUrl = $params['callback_url']
            ?? $settings['callback_url']
            ?? url('/api/v1/mpesa/callback');

        $accountReference = substr($params['account_reference'] ?? 'Payment', 0, 12);
        $transactionDesc = substr($params['transaction_desc'] ?? 'Payment', 0, 13);

        $token = $this->getAccessToken($shortcode);
        $url = $this->getBaseUrl() . '/mpesa/stkpush/v1/processrequest';

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => $transactionType,
            'Amount' => $amountInt,
            'PartyA' => $normalizedPhone,
            'PartyB' => $shortcode,
            'PhoneNumber' => $normalizedPhone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $accountReference,
            'TransactionDesc' => $transactionDesc,
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($url, $payload);

        $body = $response->json() ?? [];

        Log::info('Daraja STK Push Dispatched', [
            'url' => $url,
            'phone' => $normalizedPhone,
            'amount' => $amountInt,
            'status' => $response->status(),
            'response' => $body,
        ]);

        $isSuccess = $response->successful() && (($body['ResponseCode'] ?? null) === '0');

        return [
            'success' => $isSuccess,
            'status_code' => $response->status(),
            'merchant_request_id' => $body['MerchantRequestID'] ?? null,
            'checkout_request_id' => $body['CheckoutRequestID'] ?? null,
            'response_code' => $body['ResponseCode'] ?? null,
            'response_description' => $body['ResponseDescription'] ?? ($body['errorMessage'] ?? 'Request processed'),
            'customer_message' => $body['CustomerMessage'] ?? null,
            'raw' => $body,
        ];
    }
}

