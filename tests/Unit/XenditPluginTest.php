<?php

use OpenKOS\PaymentXendit\XenditGateway;
use OpenKOS\PaymentXendit\XenditPlugin;
use OpenKOS\Platform\OpenKOSManager;

it('registers the Xendit gateway and its settings', function () {
    $platform = app(OpenKOSManager::class);
    $plugin = new XenditPlugin;

    $plugin->register($platform);

    expect($plugin->manifest()->id)->toBe('openkos/payment-xendit')
        ->and($platform->payments()->gateways()['xendit'])->toBe(XenditGateway::class)
        ->and($platform->settings()->definitions())->toHaveKeys([
            'xendit.api_key',
            'xendit.webhook_username',
            'xendit.webhook_password',
            'xendit.base_url',
        ])
        ->and($platform->settings()->definitions()['xendit.api_key']->type)->toBe('encrypted')
        ->and($platform->settings()->definitions()['xendit.webhook_password']->type)->toBe('encrypted');
});
