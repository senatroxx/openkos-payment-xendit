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
- `webhook_auth_mode` — `basic` or `token`. Existing configurations without
  this field default to `basic`. The OpenKOS settings page renders this as a
  segmented toggle.
- `webhook_username` — Basic-auth username when `webhook_auth_mode` is `basic`.
- `webhook_password` — Basic-auth password when `webhook_auth_mode` is `basic`.
- `webhook_token` — Xendit callback token when `webhook_auth_mode` is `token`.

OpenKOS stores these fields under the `xendit` gateway entry in its encrypted
payment gateway configuration.

The package currently advertises IDR only through the optional platform
currency capability. Xendit's Payment Session API documents additional
currencies, but the supported currency set depends on the transaction country,
merchant account, and enabled payment channels. This plugin keeps the
existing Indonesia Payment Session flow conservative until broader account
support is verified. It returns Xendit's `payment_link_url` as the checkout URL
and uses `payment_session_id` as the provider reference.

Configure the host application's payment webhook route as the Xendit Payment
Session webhook URL. This package does not add routes or controllers. It
accepts `payment_session.completed` as `settled` and
`payment_session.expired` as `expired`.

Use the Xendit secret API key for Payment Session creation. For webhook
authentication, select the mode supported by the Xendit webhook configuration:
Basic Auth uses the configured username and password; token mode uses the
`x-callback-token` value from Xendit's Webhook settings. Webhook state remains
authoritative for payment settlement; browser redirects are not trusted.

The package also implements the optional platform status-lookup capability.
OpenKOS checks GET /sessions/{payment_session_id} for stale or uncertain
attempts and maps ACTIVE, COMPLETED, EXPIRED, and CANCELED to the platform
payment statuses. Webhooks and status lookups use the same persisted attempt
and accounting flow in the host application.

The package also implements the optional platform currency capability. Hosts
must treat the declared list as the provider's verified support boundary and
must not perform currency conversion or advertise currencies not returned by
the plugin.

See Xendit's [Create a session](https://docs.xendit.co/apidocs/create-session)
and [Payment Session webhook](https://docs.xendit.co/apidocs/webhook-notification-sent-defined-webhook-url-updates-payment-session)
references for provider configuration.

Refunds, payouts, recurring payments, and non-hosted checkout modes are not
part of this package.
