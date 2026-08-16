<?php

use Illuminate\Support\Facades\Http;
use OpenKOS\Core\Data\Payment\Money;
use OpenKOS\Core\Data\Payment\PaymentRequest;
use OpenKOS\Core\Data\Payment\PaymentWebhookRequest;
use OpenKOS\Core\Enums\PaymentStatus;
use OpenKOS\Core\Exceptions\PaymentWebhookPayloadException;
use OpenKOS\Core\Exceptions\PaymentWebhookVerificationException;
use OpenKOS\PaymentXendit\XenditGateway;
use OpenKOS\PaymentXendit\XenditPlugin;
use OpenKOS\Platform\OpenKOSManager;

beforeEach(function () {
    $platform = app(OpenKOSManager::class);
    (new XenditPlugin)->register($platform);

    $this->gateway = new XenditGateway([
        'api_key' => 'xnd_test_key',
        'webhook_username' => 'webhook-user',
        'webhook_password' => 'webhook-pass',
    ]);
});

it('creates an IDR Payment Session and returns hosted checkout instructions', function () {
    Http::fake([
        'https://api.xendit.co/sessions' => Http::response([
            'payment_session_id' => 'ps-test-123',
            'reference_id' => 'invoice-123',
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'status' => 'ACTIVE',
            'amount' => 150000,
            'currency' => 'IDR',
            'payment_link_url' => 'https://xen.to/test-123',
            'expires_at' => '2026-08-15T01:00:00Z',
        ], 201),
    ]);

    $result = $this->gateway->createPayment(new PaymentRequest(
        'invoice-123',
        new Money(150000, 'IDR'),
        'Rent invoice',
        ['lease_id' => 'lease-123'],
    ));

    expect($result->providerReference)->toBe('ps-test-123')
        ->and($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->instructions->url)->toBe('https://xen.to/test-123')
        ->and($result->expiresAt?->format(DATE_ATOM))->toBe('2026-08-15T01:00:00+00:00');

    Http::assertSent(function ($request): bool {
        $payload = json_decode($request->body(), true);

        return $request->url() === 'https://api.xendit.co/sessions'
            && ($request->headers()['Authorization'][0] ?? null) === 'Basic '.base64_encode('xnd_test_key:')
            && $payload === [
                'reference_id' => 'invoice-123',
                'session_type' => 'PAY',
                'mode' => 'PAYMENT_LINK',
                'amount' => 150000,
                'currency' => 'IDR',
                'country' => 'ID',
                'allow_save_payment_method' => 'DISABLED',
                'description' => 'Rent invoice',
                'metadata' => ['lease_id' => 'lease-123'],
            ];
    });
});

it('rejects currencies outside the IDR first version', function () {
    expect(fn () => $this->gateway->createPayment(new PaymentRequest(
        'invoice-123',
        new Money(100, 'PHP'),
    )))->toThrow(InvalidArgumentException::class, 'IDR only');
});

it('fails when Xendit rejects session creation', function () {
    Http::fake([
        'https://api.xendit.co/sessions' => Http::response(['error_code' => 'INVALID_API_KEY'], 401),
    ]);

    expect(fn () => $this->gateway->createPayment(new PaymentRequest(
        'invoice-123',
        new Money(100, 'IDR'),
    )))->toThrow(RuntimeException::class, 'creation failed');
});

it('normalizes a completed Payment Session webhook', function () {
    $result = $this->gateway->handleCallback(new PaymentWebhookRequest(
        rawBody: json_encode([
            'event' => 'payment_session.completed',
            'created' => '2026-08-15T01:02:03Z',
            'data' => [
                'payment_session_id' => 'ps-test-123',
                'reference_id' => 'invoice-123',
                'session_type' => 'PAY',
                'mode' => 'PAYMENT_LINK',
                'status' => 'COMPLETED',
                'amount' => 150000,
                'currency' => 'IDR',
                'payment_id' => 'py-test-123',
                'payment_request_id' => 'pr-test-123',
            ],
        ], JSON_THROW_ON_ERROR),
        headers: ['Authorization' => 'Basic '.base64_encode('webhook-user:webhook-pass')],
    ));

    expect($result->eventReference)->toBe('payment_session.completed:ps-test-123')
        ->and($result->providerReference)->toBe('ps-test-123')
        ->and($result->reference)->toBe('invoice-123')
        ->and($result->status)->toBe(PaymentStatus::Settled)
        ->and($result->amount?->minorUnits)->toBe(150000)
        ->and($result->metadata)->toBe([
            'payment_id' => 'py-test-123',
            'payment_request_id' => 'pr-test-123',
        ]);
});

it('normalizes an expired Payment Session webhook', function () {
    $result = $this->gateway->handleCallback(new PaymentWebhookRequest(
        rawBody: json_encode([
            'event' => 'payment_session.expired',
            'data' => [
                'payment_session_id' => 'ps-test-123',
                'reference_id' => 'invoice-123',
                'session_type' => 'PAY',
                'mode' => 'PAYMENT_LINK',
                'status' => 'EXPIRED',
                'amount' => 150000,
                'currency' => 'IDR',
                'updated' => '2026-08-15T01:05:00Z',
            ],
        ], JSON_THROW_ON_ERROR),
        headers: ['authorization' => 'Basic '.base64_encode('webhook-user:webhook-pass')],
    ));

    expect($result->status)->toBe(PaymentStatus::Expired)
        ->and($result->occurredAt?->format(DATE_ATOM))->toBe('2026-08-15T01:05:00+00:00');
});

it('rejects a Payment Session webhook with invalid basic authentication', function () {
    expect(fn () => $this->gateway->handleCallback(new PaymentWebhookRequest(
        rawBody: '{}',
        headers: ['Authorization' => 'Basic '.base64_encode('wrong:credentials')],
    )))->toThrow(PaymentWebhookVerificationException::class, 'authentication is invalid');
});

it('normalizes a Payment Session webhook with a callback token', function () {
    $gateway = new XenditGateway([
        'webhook_auth_mode' => 'token',
        'webhook_token' => 'callback-token',
    ]);

    $result = $gateway->handleCallback(new PaymentWebhookRequest(
        rawBody: json_encode([
            'event' => 'payment_session.expired',
            'data' => [
                'payment_session_id' => 'ps-test-123',
                'reference_id' => 'invoice-123',
                'session_type' => 'PAY',
                'mode' => 'PAYMENT_LINK',
                'status' => 'EXPIRED',
                'amount' => 150000,
                'currency' => 'IDR',
            ],
        ], JSON_THROW_ON_ERROR),
        headers: ['X-CALLBACK-TOKEN' => ['callback-token']],
    ));

    expect($result->status)->toBe(PaymentStatus::Expired)
        ->and($result->providerReference)->toBe('ps-test-123');
});

it('rejects a Payment Session webhook with an invalid callback token', function () {
    $gateway = new XenditGateway([
        'webhook_auth_mode' => 'token',
        'webhook_token' => 'callback-token',
    ]);

    expect(fn () => $gateway->handleCallback(new PaymentWebhookRequest(
        rawBody: '{}',
        headers: ['x-callback-token' => 'wrong-token'],
    )))->toThrow(PaymentWebhookVerificationException::class, 'authentication is invalid');
});

it('rejects a Payment Session webhook when the callback token is not configured', function () {
    $gateway = new XenditGateway([
        'webhook_auth_mode' => 'token',
    ]);

    expect(fn () => $gateway->handleCallback(new PaymentWebhookRequest(
        rawBody: '{}',
        headers: ['x-callback-token' => 'callback-token'],
    )))->toThrow(PaymentWebhookVerificationException::class, 'authentication is not configured');
});

it('rejects an unsupported webhook authentication mode', function () {
    $gateway = new XenditGateway([
        'webhook_auth_mode' => 'signature',
    ]);

    expect(fn () => $gateway->handleCallback(new PaymentWebhookRequest(
        rawBody: '{}',
    )))->toThrow(PaymentWebhookVerificationException::class, 'authentication mode is invalid');
});

it('classifies authenticated invalid JSON as a malformed payload', function () {
    expect(fn () => $this->gateway->handleCallback(new PaymentWebhookRequest(
        rawBody: '{invalid-json',
        headers: ['Authorization' => 'Basic '.base64_encode('webhook-user:webhook-pass')],
    )))->toThrow(PaymentWebhookPayloadException::class, 'JSON is invalid');
});

it('rejects unsupported or malformed Payment Session webhooks', function (array $body) {
    expect(fn () => $this->gateway->handleCallback(new PaymentWebhookRequest(
        rawBody: json_encode($body, JSON_THROW_ON_ERROR),
        headers: ['Authorization' => 'Basic '.base64_encode('webhook-user:webhook-pass')],
    )))->toThrow(PaymentWebhookPayloadException::class);
})->with([
    [['event' => 'payment.created', 'data' => []]],
    [['event' => 'payment_session.completed', 'data' => ['status' => 'COMPLETED']]],
    [['event' => 'payment_session.completed', 'data' => [
        'payment_session_id' => 'ps-test-123',
        'reference_id' => 'invoice-123',
        'session_type' => 'PAY',
        'mode' => 'PAYMENT_LINK',
        'status' => 'COMPLETED',
        'amount' => 150000,
        'currency' => 'USD',
    ]]],
]);

it('omits blank descriptions', function () {
    Http::fake([
        'https://api.xendit.co/sessions' => Http::response([
            'payment_session_id' => 'ps-test-123',
            'reference_id' => 'invoice-123',
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'status' => 'ACTIVE',
            'amount' => 100,
            'currency' => 'IDR',
            'payment_link_url' => 'https://xen.to/test-123',
        ], 201),
    ]);

    $this->gateway->createPayment(new PaymentRequest(
        'invoice-123',
        new Money(100, 'IDR'),
        '   ',
    ));

    Http::assertSent(function ($request): bool {
        $payload = json_decode($request->body(), true);

        return ! array_key_exists('description', $payload);
    });
});

it('rejects Xendit reference and metadata limits before making a request', function () {
    Http::fake();

    expect(fn () => $this->gateway->createPayment(new PaymentRequest(
        str_repeat('r', 65),
        new Money(100, 'IDR'),
    )))->toThrow(InvalidArgumentException::class, '64 characters');

    expect(fn () => $this->gateway->createPayment(new PaymentRequest(
        'invoice-123',
        new Money(100, 'IDR'),
        metadata: [str_repeat('k', 41) => 'value'],
    )))->toThrow(InvalidArgumentException::class, 'metadata keys');

    expect(fn () => $this->gateway->createPayment(new PaymentRequest(
        'invoice-123',
        new Money(100, 'IDR'),
        metadata: ['key' => str_repeat('v', 501)],
    )))->toThrow(InvalidArgumentException::class, 'metadata values');

    Http::assertNothingSent();
});
