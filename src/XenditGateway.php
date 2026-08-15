<?php

namespace OpenKOS\PaymentXendit;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use OpenKOS\Core\Contracts\PaymentGateway;
use OpenKOS\Core\Data\Payment\CheckoutInstructions;
use OpenKOS\Core\Data\Payment\Money;
use OpenKOS\Core\Data\Payment\PaymentCreationResult;
use OpenKOS\Core\Data\Payment\PaymentRequest;
use OpenKOS\Core\Data\Payment\PaymentWebhookRequest;
use OpenKOS\Core\Data\Payment\PaymentWebhookResult;
use OpenKOS\Core\Enums\PaymentStatus;
use OpenKOS\Core\Exceptions\PaymentWebhookVerificationException;
use OpenKOS\Platform\Settings\SettingsManager;
use RuntimeException;
use Throwable;

final class XenditGateway implements PaymentGateway
{
    private const DEFAULT_BASE_URL = 'https://api.xendit.co';

    public function __construct(private readonly SettingsManager $settings) {}

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

        $apiKey = $this->requiredSetting('xendit.api_key');
        $payload = [
            'reference_id' => $request->reference,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'amount' => $request->amount->minorUnits,
            'currency' => 'IDR',
            'country' => 'ID',
            'allow_save_payment_method' => 'DISABLED',
        ];

        if ($request->description !== null) {
            $payload['description'] = $request->description;
        }

        if ($request->metadata !== []) {
            $payload['metadata'] = $request->metadata;
        }

        $response = Http::withBasicAuth($apiKey, '')
            ->acceptJson()
            ->asJson()
            ->post($this->endpoint('/sessions'), $payload);

        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            throw new RuntimeException('Xendit Payment Session creation failed.');
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

    public function handleCallback(PaymentWebhookRequest $request): PaymentWebhookResult
    {
        $this->verifyBasicAuthentication($request);
        $body = $this->decodeWebhook($request->rawBody);
        $event = $this->requiredString($body, 'event', 'Xendit webhook', true);
        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw new PaymentWebhookVerificationException('Xendit webhook data is invalid.');
        }

        $status = match ($event) {
            'payment_session.completed' => PaymentStatus::Settled,
            'payment_session.expired' => PaymentStatus::Expired,
            default => throw new PaymentWebhookVerificationException('Unsupported Xendit webhook event.'),
        };

        $this->assertWebhookValue($data, 'session_type', 'PAY');
        $this->assertWebhookValue($data, 'mode', 'PAYMENT_LINK');
        $this->assertWebhookValue($data, 'status', $status === PaymentStatus::Settled ? 'COMPLETED' : 'EXPIRED');

        $sessionId = $this->requiredString($data, 'payment_session_id', 'Xendit webhook', true);
        $reference = $this->requiredString($data, 'reference_id', 'Xendit webhook', true);
        $currency = $this->requiredString($data, 'currency', 'Xendit webhook', true);
        $amount = $this->requiredInteger($data, 'amount', 'Xendit webhook', true);

        if ($currency !== 'IDR') {
            throw new PaymentWebhookVerificationException('Xendit webhook currency is unsupported.');
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
            'webhook_username' => ['label' => 'Payment Session webhook username', 'required' => true],
            'webhook_password' => ['label' => 'Payment Session webhook password', 'type' => 'password', 'required' => true],
            'base_url' => ['label' => 'API base URL', 'default' => self::DEFAULT_BASE_URL],
        ];
    }

    private function verifyBasicAuthentication(PaymentWebhookRequest $request): void
    {
        $authorization = $this->header($request->headers, 'authorization');
        $username = $this->setting('xendit.webhook_username');
        $password = $this->setting('xendit.webhook_password');

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

    /**
     * @return array<string, mixed>
     */
    private function decodeWebhook(string $rawBody): array
    {
        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentWebhookVerificationException('Xendit webhook JSON is invalid.');
        }

        if (! is_array($body)) {
            throw new PaymentWebhookVerificationException('Xendit webhook JSON is invalid.');
        }

        return $body;
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) ($this->setting('xendit.base_url') ?? self::DEFAULT_BASE_URL), '/').$path;
    }

    private function requiredSetting(string $key): string
    {
        $value = $this->setting($key);

        if ($value === null || $value === '') {
            throw new RuntimeException("Xendit setting [{$key}] is not configured.");
        }

        return $value;
    }

    private function setting(string $key): ?string
    {
        $value = $this->settings->get($key);

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
                ? new PaymentWebhookVerificationException($message)
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
                ? new PaymentWebhookVerificationException($message)
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
            throw new PaymentWebhookVerificationException("Xendit webhook field [{$key}] is invalid.");
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
                ? new PaymentWebhookVerificationException($message)
                : new RuntimeException($message);
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            $message = "{$source} date is invalid.";
            throw $webhook
                ? new PaymentWebhookVerificationException($message)
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
