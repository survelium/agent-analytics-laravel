<?php

namespace Survelium\LaravelAgentAnalytics;

use Survelium\AgentAnalytics\Config;

final class Integration
{
    public static function isActive(): bool
    {
        return (bool) config('survelium.enabled') && filled(config('survelium.ingest_key'));
    }

    public static function config(): Config
    {
        return new Config(
            apiKey: (string) config('survelium.ingest_key'),
            endpoint: (string) config('survelium.endpoint', Config::DEFAULT_ENDPOINT),
            trackReferrals: (bool) config('survelium.track_referrals', true),
            denyPaths: array_values((array) config('survelium.deny_paths', [])),
            storagePath: (string) config('survelium.storage_path'),
            batchSize: (int) config('survelium.batch_size', 50),
            flushIntervalSeconds: (int) config('survelium.flush_interval_seconds', 60),
        );
    }
}
