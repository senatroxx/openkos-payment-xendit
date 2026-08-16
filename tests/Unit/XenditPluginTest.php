<?php

use OpenKOS\PaymentXendit\XenditGateway;
use OpenKOS\PaymentXendit\XenditPlugin;
use OpenKOS\Platform\OpenKOSManager;

it('registers the Xendit gateway without platform settings', function () {
    $platform = app(OpenKOSManager::class);
    $plugin = new XenditPlugin;

    $plugin->register($platform);

    $schema = (new XenditGateway)->configurationSchema();

    expect($plugin->manifest()->id)->toBe('openkos/payment-xendit')
        ->and($plugin->manifest()->version)->toBe('0.1.1')
        ->and($platform->payments()->gateways()['xendit'])->toBe(XenditGateway::class)
        ->and($platform->settings()->definitions())->toBe([])
        ->and($schema['webhook_auth_mode']['options'])->toBe([
            ['value' => 'basic', 'label' => 'Basic Auth'],
            ['value' => 'token', 'label' => 'Callback token'],
        ])
        ->and($schema['webhook_username']['type'])->toBe('password')
        ->and($schema['webhook_password']['type'])->toBe('password')
        ->and($schema['webhook_token']['type'])->toBe('password');
});
