<?php

namespace Survelium\LaravelAgentAnalytics;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Survelium\AgentAnalytics\AgentAnalytics;
use Survelium\AgentAnalytics\Storage\FileStorage;
use Survelium\AgentAnalytics\Transport\CurlTransport;
use Survelium\AgentAnalytics\Transport\StreamTransport;
use Survelium\AgentAnalytics\Transport\Transport;
use Survelium\LaravelAgentAnalytics\Commands\FlushCommand;
use Survelium\LaravelAgentAnalytics\Commands\StatusCommand;
use Survelium\LaravelAgentAnalytics\Http\Middleware\TrackAgentAnalytics;

class AgentAnalyticsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('survelium')
            ->hasConfigFile('survelium')
            ->hasCommands([FlushCommand::class, StatusCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->bindIf(Transport::class, fn (): Transport => function_exists('curl_init') ? new CurlTransport : new StreamTransport);

        $this->app->singleton(AgentAnalytics::class, function ($app): AgentAnalytics {
            $config = Integration::config();

            return AgentAnalytics::make($config, new FileStorage($config->storagePath()), $app->make(Transport::class));
        });
    }

    public function packageBooted(): void
    {
        $kernel = $this->app->make(HttpKernelContract::class);

        foreach ((array) config('survelium.middleware_groups', []) as $group) {
            // The HTTP kernel re-syncs its groups onto the router, so a group it owns must be changed through it.
            if ($kernel instanceof HttpKernel && array_key_exists($group, $kernel->getMiddlewareGroups())) {
                $kernel->appendMiddlewareToGroup($group, TrackAgentAnalytics::class);
            } else {
                $this->app->make(Router::class)->pushMiddlewareToGroup($group, TrackAgentAnalytics::class);
            }
        }

        if (config('survelium.schedule')) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command(FlushCommand::class)->everyFiveMinutes()->withoutOverlapping();
            });
        }
    }
}
