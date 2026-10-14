<?php

namespace Survelium\LaravelAgentAnalytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Survelium\AgentAnalytics\AgentAnalytics;
use Survelium\AgentAnalytics\Hit;
use Survelium\LaravelAgentAnalytics\Integration;
use Survelium\LaravelAgentAnalytics\Jobs\FlushAgentAnalytics;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Does its work in terminate(), after the response has been sent.
 */
class TrackAgentAnalytics
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! Integration::isActive() || ! $this->shouldTrack($request)) {
            return;
        }

        try {
            $analytics = app(AgentAnalytics::class);

            $analytics->record(new Hit(
                path: $request->getRequestUri(),
                userAgent: $request->userAgent(),
                referrer: $request->headers->get('referer'),
                ip: $request->ip(),
                status: $response->getStatusCode(),
            ));

            if ($analytics->shouldFlush()) {
                FlushAgentAnalytics::dispatch();
            }
        } catch (Throwable $exception) {
            // Analytics must never break the site; surface it in the log only.
            report($exception);
        }
    }

    /**
     * Page views only: crawlers fetch pages with GET/HEAD, and XHR or Livewire
     * round-trips would inflate the daily total.
     */
    private function shouldTrack(Request $request): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && ! $request->ajax()
            && ! $request->headers->has('X-Livewire')
            && ! $request->prefetch();
    }
}
