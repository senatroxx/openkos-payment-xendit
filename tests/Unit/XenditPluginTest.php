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
        ->and($plugin->manifest()->version)->toBe('0.1.5')
        ->and($platform->payments()->gateways()['xendit'])->toBe(XenditGateway::class)
        ->and($platform->settings()->definitions())->toBe([])
        ->and($schema['webhook_auth_mode']['presentation'])->toBe('segmented')
        ->and($schema['webhook_auth_mode']['label'])->toBe('Webhook authentication')
        ->and($schema['webhook_auth_mode']['default'])->toBe('basic')
        ->and($schema['webhook_auth_mode']['options'])->toBe([
            ['value' => 'basic', 'label' => 'Basic Auth'],
            ['value' => 'token', 'label' => 'Callback token'],
        ])
        ->and($schema['webhook_setup']['type'])->toBe('info')
        ->and($schema['webhook_setup']['label'])->toBe('Webhook setup')
        ->and($schema['webhook_setup']['instructions'])->toBe([
            'Open the Xendit webhook settings.',
            'Add the full webhook URL shown below.',
            'Enable Payment Session Completed and Payment Session Expired.',
        ])
        ->and($schema['webhook_setup']['link'])->toBe([
            'label' => 'Open Xendit webhook settings',
            'url' => 'https://dashboard.xendit.co/settings/developers#webhooks',
        ])
        ->and($schema['webhook_setup']['url'])->toBe('/api/webhooks/payment/xendit')
        ->and($schema['webhook_username']['label'])->toBe('Webhook username')
        ->and($schema['webhook_username']['description'])->toBe('Enter your Secret API key as the username and leave the password field empty.')
        ->and($schema['webhook_password']['label'])->toBe('Webhook password')
        ->and($schema['webhook_token']['label'])->toBe('Webhook callback token')
        ->and($schema['webhook_username']['visible_when'])->toBe([
            'field' => 'webhook_auth_mode',
            'value' => 'basic',
        ])
        ->and($schema['webhook_token']['visible_when'])->toBe([
            'field' => 'webhook_auth_mode',
            'value' => 'token',
        ])
        ->and($schema['webhook_username']['type'])->toBe('password')
        ->and($schema['webhook_password']['type'])->toBe('password')
        ->and($schema['webhook_token']['type'])->toBe('password');
});
