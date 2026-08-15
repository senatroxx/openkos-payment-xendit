<?php

namespace OpenKOS\PaymentXendit;

use OpenKOS\Platform\OpenKOSManager;
use OpenKOS\Platform\Plugin\Plugin;
use OpenKOS\Platform\Plugin\PluginManifest;
use OpenKOS\Platform\Settings\SettingDefinition;

final class XenditPlugin extends Plugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'openkos/payment-xendit',
            name: 'Xendit Payments',
            version: '0.1.0',
            description: 'Hosted Xendit Payment Session gateway.',
            coreVersion: '^0.2',
        );
    }

    public function register(OpenKOSManager $platform): void
    {
        $platform->payments()->registerGateway('xendit', XenditGateway::class);

        foreach ($this->settings() as $setting) {
            $platform->settings()->registerSetting($setting);
        }
    }

    /**
     * @return array<int, SettingDefinition>
     */
    private function settings(): array
    {
        return [
            new SettingDefinition(
                key: 'xendit.api_key',
                label: 'Xendit API key',
                type: 'encrypted',
                rules: ['nullable', 'string'],
            ),
            new SettingDefinition(
                key: 'xendit.webhook_username',
                label: 'Xendit Payment Session webhook username',
                type: 'encrypted',
                rules: ['nullable', 'string'],
            ),
            new SettingDefinition(
                key: 'xendit.webhook_password',
                label: 'Xendit Payment Session webhook password',
                type: 'encrypted',
                rules: ['nullable', 'string'],
            ),
            new SettingDefinition(
                key: 'xendit.base_url',
                label: 'Xendit API base URL',
                default: 'https://api.xendit.co',
                rules: ['required', 'url'],
            ),
        ];
    }
}
