<?php

namespace OpenKOS\PaymentXendit;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use OpenKOS\Core\Contracts\PaymentGateway;
use OpenKOS\Core\Contracts\PaymentGatewayStatusLookup;
use OpenKOS\Core\Data\Payment\CheckoutInstructions;
use OpenKOS\Core\Data\Payment\Money;
use OpenKOS\Core\Data\Payment\PaymentCreationResult;
use OpenKOS\Core\Data\Payment\PaymentProviderResult;
use OpenKOS\Core\Data\Payment\PaymentRequest;
use OpenKOS\Core\Data\Payment\PaymentStatusLookupRequest;
use OpenKOS\Core\Data\Payment\PaymentWebhookRequest;
use OpenKOS\Core\Data\Payment\PaymentWebhookResult;
use OpenKOS\Core\Enums\PaymentStatus;
use OpenKOS\Core\Exceptions\PaymentWebhookPayloadException;
use OpenKOS\Core\Exceptions\PaymentWebhookVerificationException;
use RuntimeException;
use Throwable;

final class XenditGateway implements PaymentGateway, PaymentGatewayStatusLookup
{
    private const DEFAULT_BASE_URL = 'https://api.xendit.co';

    private const WEBHOOK_AUTH_BASIC = 'basic';

    private const WEBHOOK_AUTH_TOKEN = 'token';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function key(): string
    {
        return 'xendit';
    }

    public function displayName(): string
    {
        return 'Xendit';
    }

    public function createPayment(PaymentRequest $request): PaymentCreationResult
    {
        if ($request->amount->currency !== 'IDR') {
            throw new InvalidArgumentException('Xendit payments currently support IDR only.');
        }

        $this->validateRequestLimits($request);
        $apiKey = $this->requiredConfig('api_key');
        $payload = [
            'reference_id' => $request->reference,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'amount' => $request->amount->minorUnits,
            'currency' => 'IDR',
            'country' => 'ID',
            'allow_save_payment_method' => 'DISABLED',
        ];

        if ($request->description !== null && trim($request->description) !== '') {
            $payload['description'] = $request->description;
        }

        $metadata = $this->normalizeMetadata($request->metadata);

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $response = Http::withBasicAuth($apiKey, '')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post($this->endpoint('/sessions'), $payload);

        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            $diagnostics = array_filter([
                'http_status' => $response->status(),
                'error_code' => is_scalar($body['error_code'] ?? null)
                    ? (string) $body['error_code']
                    : null,
                'error_message' => is_scalar($body['message'] ?? null)
                    ? (string) $body['message']
                    : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            Log::warning('Xendit Payment Session creation failed.', $diagnostics);

            $detail = $diagnostics['error_code'] ?? $diagnostics['error_message'] ?? null;
            $suffix = $detail === null
                ? " (HTTP {$response->status()})"
                : " (HTTP {$response->status()}: {$detail})";

            throw new RuntimeException('Xendit Payment Session creation failed'.$suffix.'.');
        }

        $sessionId = $this->requiredString($body, 'payment_session_id', 'Xendit response');
        $this->assertResponseValue($body, 'reference_id', $request->reference);
        $this->assertResponseValue($body, 'session_type', 'PAY');
        $this->assertResponseValue($body, 'mode', 'PAYMENT_LINK');
        $this->assertResponseValue($body, 'status', 'ACTIVE');
        $this->assertResponseValue($body, 'currency', 'IDR');
        $this->assertResponseValue($body, 'amount', $request->amount->minorUnits);

        return new PaymentCreationResult(
            providerReference: $sessionId,
            status: PaymentStatus::Pending,
            amount: $request->amount,
            instructions: new CheckoutInstructions(
                url: $this->requiredString($body, 'payment_link_url', 'Xendit response'),
            ),
            expiresAt: $this->optionalDate($body['expires_at'] ?? null, 'Xendit response'),
            metadata: ['session_type' => 'PAY', 'mode' => 'PAYMENT_LINK'],
        );
    }

    public function lookupPaymentStatus(PaymentStatusLookupRequest $request): PaymentProviderResult
    {
        $apiKey = $this->requiredConfig('api_key');
        $response = Http::withBasicAuth($apiKey, '')
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, fn (int $attempt): int => 100 * (2 ** ($attempt - 1)), throw: false)
            ->get($this->endpoint('/sessions/'.rawurlencode($request->providerReference)));
        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            $diagnostics = array_filter([
                'http_status' => $response->status(),
                'error_code' => is_scalar($body['error_code'] ?? null)
                    ? (string) $body['error_code']
                    : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            Log::warning('Xendit Payment Session status lookup failed.', $diagnostics);

            throw new RuntimeException('Xendit Payment Session status lookup failed.');
        }

        $sessionId = $this->requiredString($body, 'payment_session_id', 'Xendit response');
        $this->assertResponseValue($body, 'payment_session_id', $request->providerReference);
        $reference = $this->requiredString($body, 'reference_id', 'Xendit response');

        if ($request->reference !== null && $reference !== $request->reference) {
            throw new RuntimeException('Xendit response reference does not match the payment attempt.');
        }

        $this->assertResponseValue($body, 'session_type', 'PAY');
        $this->assertResponseValue($body, 'mode', 'PAYMENT_LINK');
        $currency = $this->requiredString($body, 'currency', 'Xendit response');
        $amount = $this->requiredInteger($body, 'amount', 'Xendit response');
        $providerStatus = $this->requiredString($body, 'status', 'Xendit response');
        $status = $this->normalizeSessionStatus($providerStatus);
        $updated = $this->optionalDate($body['updated'] ?? null, 'Xendit response');
        $metadata = ['provider_status' => $providerStatus];

        foreach (['payment_id', 'payment_request_id', 'payment_token_id'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                $metadata[$key] = $body[$key];
            }
        }

        return new PaymentProviderResult(
            providerReference: $sessionId,
            status: $status,
            reference: $reference,
            amount: new Money($amount, $currency),
            occurredAt: $updated,
            metadata: $metadata,
        );
    }

    public function handleCallback(PaymentWebhookRequest $request): PaymentWebhookResult
    {
        $this->verifyWebhookAuthentication($request);
        $body = $this->decodeWebhook($request->rawBody);
        $event = $this->requiredString($body, 'event', 'Xendit webhook', true);
        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw new PaymentWebhookPayloadException('Xendit webhook data is invalid.');
        }

        $status = match ($event) {
            'payment_session.completed' => PaymentStatus::Settled,
            'payment_session.expired' => PaymentStatus::Expired,
            default => throw new PaymentWebhookPayloadException('Unsupported Xendit webhook event.'),
        };

        $this->assertWebhookValue($data, 'session_type', 'PAY');
        $this->assertWebhookValue($data, 'mode', 'PAYMENT_LINK');
        $this->assertWebhookValue($data, 'status', $status === PaymentStatus::Settled ? 'COMPLETED' : 'EXPIRED');

        $sessionId = $this->requiredString($data, 'payment_session_id', 'Xendit webhook', true);
        $reference = $this->requiredString($data, 'reference_id', 'Xendit webhook', true);
        $currency = $this->requiredString($data, 'currency', 'Xendit webhook', true);
        $amount = $this->requiredInteger($data, 'amount', 'Xendit webhook', true);

        if ($currency !== 'IDR') {
            throw new PaymentWebhookPayloadException('Xendit webhook currency is unsupported.');
        }

        $metadata = [];
        foreach (['payment_id', 'payment_request_id', 'payment_token_id'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $metadata[$key] = $data[$key];
            }
        }

        return new PaymentWebhookResult(
            eventReference: $event.':'.$sessionId,
            providerReference: $sessionId,
            status: $status,
            reference: $reference,
            amount: new Money($amount, $currency),
            occurredAt: $this->optionalDate(
                $data['updated'] ?? $body['created'] ?? $data['created'] ?? null,
                'Xendit webhook',
                true,
            ),
            metadata: $metadata,
        );
    }

    public function configurationSchema(): array
    {
        return [
            'api_key' => ['label' => 'API key', 'type' => 'password', 'required' => true],
            'webhook_auth_mode' => [
                'label' => 'Webhook authentication',
                'type' => 'select',
                'presentation' => 'segmented',
                'default' => self::WEBHOOK_AUTH_BASIC,
                'options' => [
                    ['value' => self::WEBHOOK_AUTH_BASIC, 'label' => 'Basic Auth'],
                    ['value' => self::WEBHOOK_AUTH_TOKEN, 'label' => 'Callback token'],
                ],
            ],
            'webhook_setup' => [
                'label' => 'Webhook setup',
                'type' => 'info',
                'instructions' => [
                    'Open the Xendit webhook settings.',
                    'Add the full webhook URL shown below.',
                    'Enable Payment Session Completed and Payment Session Expired.',
                ],
                'link' => [
                    'label' => 'Open Xendit webhook settings',
                    'url' => 'https://dashboard.xendit.co/settings/developers#webhooks',
                ],
                'url' => '/api/webhooks/payment/xendit',
            ],
            'webhook_username' => [
                'label' => 'Webhook username',
                'type' => 'password',
                'description' => 'Enter your Secret API key as the username and leave the password field empty.',
                'visible_when' => [
                    'field' => 'webhook_auth_mode',
                    'value' => self::WEBHOOK_AUTH_BASIC,
                ],
            ],
            'webhook_password' => [
                'label' => 'Webhook password',
                'type' => 'password',
                'visible_when' => [
                    'field' => 'webhook_auth_mode',
                    'value' => self::WEBHOOK_AUTH_BASIC,
                ],
            ],
            'webhook_token' => [
                'label' => 'Webhook callback token',
                'type' => 'password',
                'visible_when' => [
                    'field' => 'webhook_auth_mode',
                    'value' => self::WEBHOOK_AUTH_TOKEN,
                ],
            ],
        ];
    }

    private function verifyWebhookAuthentication(PaymentWebhookRequest $request): void
    {
        match ($this->configValue('webhook_auth_mode') ?? self::WEBHOOK_AUTH_BASIC) {
            self::WEBHOOK_AUTH_BASIC => $this->verifyBasicAuthentication($request),
            self::WEBHOOK_AUTH_TOKEN => $this->verifyCallbackToken($request),
            default => throw new PaymentWebhookVerificationException('Xendit webhook authentication mode is invalid.'),
        };
    }

    private function verifyBasicAuthentication(PaymentWebhookRequest $request): void
    {
        $authorization = $this->header($request->headers, 'authorization');
        $username = $this->configValue('webhook_username');
        $password = $this->configValue('webhook_password');

        if ($authorization === null || $username === null || $password === null) {
            throw new PaymentWebhookVerificationException('Xendit webhook authentication is not configured.');
        }

        if (! preg_match('/\ABasic\s+([^\s]+)\z/i', $authorization, $matches)) {
            throw new PaymentWebhookVerificationException('Xendit webhook authentication is invalid.');
        }

        $credentials = base64_decode($matches[1], true);
        if ($credentials === false || ! str_contains($credentials, ':')) {
            throw new PaymentWebhookVerificationException('Xendit webhook authentication is invalid.');
        }

        [$actualUsername, $actualPassword] = explode(':', $credentials, 2);
        if (! hash_equals($username, $actualUsername) || ! hash_equals($password, $actualPassword)) {
            throw new PaymentWebhookVerificationException('Xendit webhook authentication is invalid.');
        }
    }

    private function verifyCallbackToken(PaymentWebhookRequest $request): void
    {
        $actualToken = $this->header($request->headers, 'x-callback-token');
        $expectedToken = $this->configValue('webhook_token');

        if ($actualToken === null || $expectedToken === null) {
            throw new PaymentWebhookVerificationException('Xendit webhook authentication is not configured.');
        }

        if (! hash_equals($expectedToken, $actualToken)) {
            throw new PaymentWebhookVerificationException('Xendit webhook authentication is invalid.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeWebhook(string $rawBody): array
    {
        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentWebhookPayloadException('Xendit webhook JSON is invalid.');
        }

        if (! is_array($body)) {
            throw new PaymentWebhookPayloadException('Xendit webhook JSON is invalid.');
        }

        return $body;
    }

    private function endpoint(string $path): string
    {
        return self::DEFAULT_BASE_URL.$path;
    }

    private function validateRequestLimits(PaymentRequest $request): void
    {
        if (strlen($request->reference) > 64) {
            throw new InvalidArgumentException('Xendit payment references cannot exceed 64 characters.');
        }

        if (count($request->metadata) > 50) {
            throw new InvalidArgumentException('Xendit payment metadata cannot contain more than 50 entries.');
        }

        foreach ($request->metadata as $key => $value) {
            if (strlen($key) > 40) {
                throw new InvalidArgumentException('Xendit payment metadata keys cannot exceed 40 characters.');
            }

            if (strlen((string) $value) > 500) {
                throw new InvalidArgumentException('Xendit payment metadata values cannot exceed 500 characters.');
            }
        }
    }

    private function normalizeSessionStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'ACTIVE' => PaymentStatus::Pending,
            'COMPLETED' => PaymentStatus::Settled,
            'EXPIRED' => PaymentStatus::Expired,
            'CANCELED' => PaymentStatus::Canceled,
            default => throw new RuntimeException('Xendit response session status is unsupported.'),
        };
    }

    /**
     * @param  array<string, bool|int|string|null>  $metadata
     * @return array<string, string>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalized[$key] = is_bool($value)
                ? ($value ? 'true' : 'false')
                : (string) $value;
        }

        return $normalized;
    }

    private function requiredConfig(string $key): string
    {
        $value = $this->configValue($key);

        if ($value === null || $value === '') {
            throw new RuntimeException("Xendit configuration [{$key}] is not configured.");
        }

        return $value;
    }

    private function configValue(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function requiredString(array $body, string $key, string $source, bool $webhook = false): string
    {
        $value = $body[$key] ?? null;

        if (! is_string($value) || $value === '') {
            $message = "{$source} field [{$key}] is invalid.";
            throw $webhook
                ? new PaymentWebhookPayloadException($message)
                : new RuntimeException($message);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function requiredInteger(array $body, string $key, string $source, bool $webhook = false): int
    {
        $value = $body[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            $message = "{$source} field [{$key}] is invalid.";
            throw $webhook
                ? new PaymentWebhookPayloadException($message)
                : new RuntimeException($message);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assertResponseValue(array $body, string $key, int|string $expected): void
    {
        if (($body[$key] ?? null) !== $expected) {
            throw new RuntimeException("Xendit response field [{$key}] is invalid.");
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assertWebhookValue(array $body, string $key, string $expected): void
    {
        if (($body[$key] ?? null) !== $expected) {
            throw new PaymentWebhookPayloadException("Xendit webhook field [{$key}] is invalid.");
        }
    }

    private function optionalDate(mixed $value, string $source, bool $webhook = false): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            $message = "{$source} date is invalid.";
            throw $webhook
                ? new PaymentWebhookPayloadException($message)
                : new RuntimeException($message);
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            $message = "{$source} date is invalid.";
            throw $webhook
                ? new PaymentWebhookPayloadException($message)
                : new RuntimeException($message);
        }
    }

    /**
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $value) {
            if (strtolower($header) !== strtolower($name)) {
                continue;
            }

            return is_array($value) ? ($value[0] ?? null) : $value;
        }

        return null;
    }
}
