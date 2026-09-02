<?php

namespace OpenKOS\PaymentXendit;

use OpenKOS\Platform\OpenKOSManager;
use OpenKOS\Platform\Plugin\Plugin;
use OpenKOS\Platform\Plugin\PluginManifest;

final class XenditPlugin extends Plugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'openkos/payment-xendit',
            name: 'Xendit Payments',
            version: '0.1.6',
            description: 'Hosted Xendit Payment Session gateway.',
            coreVersion: '^0.2',
        );
    }

    public function register(OpenKOSManager $platform): void
    {
        $platform->payments()->registerGateway('xendit', XenditGateway::class);
    }
}
