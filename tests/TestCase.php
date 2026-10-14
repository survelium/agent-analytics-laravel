<?php

namespace Survelium\LaravelAgentAnalytics\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Survelium\AgentAnalytics\Transport\FakeTransport;
use Survelium\AgentAnalytics\Transport\Transport;
use Survelium\LaravelAgentAnalytics\AgentAnalyticsServiceProvider;

class TestCase extends Orchestra
{
    public string $storagePath;

    public FakeTransport $transport;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'survelium-laravel-test-'.bin2hex(random_bytes(6));

        parent::setUp();

        $this->transport = new FakeTransport;
        $this->app->instance(Transport::class, $this->transport);
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->storagePath);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AgentAnalyticsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('survelium.ingest_key', 'svk_test');
        $app['config']->set('survelium.endpoint', 'https://survelium.test');
        $app['config']->set('survelium.storage_path', $this->storagePath);
    }
}
