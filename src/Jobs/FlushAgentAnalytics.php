<?php

namespace Survelium\LaravelAgentAnalytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Survelium\AgentAnalytics\AgentAnalytics;

/**
 * Unique for a minute so a busy site queues one flush, not one per request.
 * The core retries on its own schedule, so the job itself never does.
 */
class FlushAgentAnalytics implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onConnection(config('survelium.queue.connection'));
        $this->onQueue(config('survelium.queue.name'));
    }

    public function handle(AgentAnalytics $analytics): void
    {
        $analytics->flush();
    }
}
