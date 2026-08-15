<?php

use OpenKOS\PaymentXendit\XenditGateway;
use OpenKOS\PaymentXendit\XenditPlugin;
use OpenKOS\Platform\OpenKOSManager;

it('registers the Xendit gateway without platform settings', function () {
    $platform = app(OpenKOSManager::class);
    $plugin = new XenditPlugin;

    $plugin->register($platform);

    expect($plugin->manifest()->id)->toBe('openkos/payment-xendit')
        ->and($platform->payments()->gateways()['xendit'])->toBe(XenditGateway::class)
        ->and($platform->settings()->definitions())->toBe([])
        ->and((new XenditGateway)->configurationSchema()['webhook_username']['type'])->toBe('password');
});
