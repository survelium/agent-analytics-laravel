<?php

namespace Survelium\LaravelAgentAnalytics\Commands;

use Illuminate\Console\Command;
use Survelium\AgentAnalytics\AgentAnalytics;
use Survelium\LaravelAgentAnalytics\Integration;

class FlushCommand extends Command
{
    protected $signature = 'survelium:flush {--force : Send now, even if the batch is not due (useful to test the key)}';

    protected $description = 'Send buffered AI crawler hits, AI referrals and the daily total to Survelium';

    public function handle(AgentAnalytics $analytics): int
    {
        if (! Integration::isActive()) {
            $this->components->warn('Survelium is not configured: set SURVELIUM_INGEST_KEY.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $analytics->shouldFlush()) {
            $this->components->info('Nothing due yet.');

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            // A forced run ignores any back-off from earlier failures.
            $state = $analytics->storage->state();
            unset($state['next_attempt_at'], $state['paused_until']);
            $analytics->storage->putState($state);
        }

        $status = $analytics->flush();
        $error = $analytics->storage->state()['last_error'] ?? null;

        if ($status === null) {
            $this->components->info('Nothing to send, or another flush is running.');

            return self::SUCCESS;
        }

        if ($status === 202) {
            $this->components->info('Sent. Survelium answered 202.');

            return self::SUCCESS;
        }

        $this->components->error("Survelium answered {$status}".($error ? " ({$error})" : '').'. Data is kept and retried.');

        return self::FAILURE;
    }
}
