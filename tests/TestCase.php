<?php

namespace Tests;

use OpenKOS\Core\Contracts\SettingsStore;
use OpenKOS\Platform\PlatformServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PlatformServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->bind(SettingsStore::class, fn () => new class implements SettingsStore
        {
            private array $values = [];

            public function get(string $key): mixed
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $key, mixed $value, string $type): void
            {
                $this->values[$key] = $value;
            }
        });

        $app['config']->set('platform.plugins', []);
    }
}
