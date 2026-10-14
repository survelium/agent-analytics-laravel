<?php

namespace Survelium\LaravelAgentAnalytics\Commands;

use Illuminate\Console\Command;
use Survelium\AgentAnalytics\AgentAnalytics;
use Survelium\LaravelAgentAnalytics\Integration;

class StatusCommand extends Command
{
    protected $signature = 'survelium:status';

    protected $description = 'Show the Survelium agent analytics buffer, last send and bot list';

    public function handle(AgentAnalytics $analytics): int
    {
        $status = $analytics->status();
        $state = $status['state'];
        $time = fn (?int $timestamp): string => $timestamp ? date('Y-m-d H:i:s', $timestamp) : '—';

        $this->components->twoColumnDetail('Active', Integration::isActive() ? '<fg=green>yes</>' : '<fg=yellow>no (set SURVELIUM_INGEST_KEY)</>');
        $this->components->twoColumnDetail('Ingest key', filled(config('survelium.ingest_key')) ? mb_substr((string) config('survelium.ingest_key'), 0, 12).'…' : '—');
        $this->components->twoColumnDetail('Endpoint', (string) config('survelium.endpoint'));
        $this->components->twoColumnDetail('Buffered items', (string) $status['buffered']['count']);
        $this->components->twoColumnDetail('Oldest buffered', $time($status['buffered']['oldest']));
        $this->components->twoColumnDetail('Last attempt', $time($state['last_attempt_at'] ?? null).(isset($state['last_status']) ? " (HTTP {$state['last_status']})" : ''));
        $this->components->twoColumnDetail('Last success', $time($state['last_success_at'] ?? null));
        $this->components->twoColumnDetail('Last error', (string) ($state['last_error'] ?? '—'));
        $this->components->twoColumnDetail('Paused until', $time($state['paused_until'] ?? $state['next_attempt_at'] ?? null));
        $this->components->twoColumnDetail('Bot list', $status['bots'].' bots, version '.($status['signatures_version'] ? mb_substr($status['signatures_version'], 0, 8) : 'bundled'));
        $this->components->twoColumnDetail('Core version', $status['version']);

        return self::SUCCESS;
    }
}
