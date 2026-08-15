# OpenKOS Xendit Payment Gateway

Standalone Xendit gateway for OpenKOS. It creates hosted `PAY` Payment
Sessions with `mode: PAYMENT_LINK` and normalizes Session lifecycle webhooks
into the OpenKOS payment contract.

## Installation

```sh
composer require openkos/payment-xendit
```

OpenKOS discovers the plugin through Composer metadata and registers the
`xendit` payment gateway. Configure these fields on the host application's
Payment Gateway settings page:

- `api_key` — Xendit secret API key.
- `webhook_username` — Basic-auth username configured for the Payment
  Session webhook endpoint.
- `webhook_password` — Basic-auth password configured for the Payment
  Session webhook endpoint.

OpenKOS stores these fields under the `xendit` gateway entry in its encrypted
payment gateway configuration.

The package is IDR-only for its first version. It returns Xendit's
`payment_link_url` as the checkout URL and uses `payment_session_id` as the
provider reference.

Configure the host application's payment webhook route as the Xendit Payment
Session webhook URL. This package does not add routes or controllers. It
accepts `payment_session.completed` as `settled` and
`payment_session.expired` as `expired`.

See Xendit's [Create a session](https://docs.xendit.co/apidocs/create-session)
and [Payment Session webhook](https://docs.xendit.co/apidocs/webhook-notification-sent-defined-webhook-url-updates-payment-session)
references for provider configuration.

Refunds, payouts, recurring payments, and non-hosted checkout modes are not
part of this package.
