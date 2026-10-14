# Survelium Agent Analytics for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/survelium/agent-analytics-laravel.svg?style=flat-square)](https://packagist.org/packages/survelium/agent-analytics-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/survelium/agent-analytics-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/survelium/agent-analytics-laravel/actions/workflows/run-tests.yml)

See which AI crawlers read your Laravel site and how many people AI assistants send you, in
[Survelium](https://survelium.com).

AI crawlers (GPTBot, ClaudeBot, PerplexityBot, …) do not run JavaScript, so a JS snippet never sees them. This
package adds a middleware that recognises them on the server, plus visitors arriving from ChatGPT, Perplexity,
Claude, Gemini or Copilot, and sends them to Survelium in batches from your queue. It is built on
[`survelium/agent-analytics-php`](https://github.com/survelium/agent-analytics-php).

## What leaves your server

| | Sent | Never sent |
|---|---|---|
| AI crawler hit | time, user agent, path, HTTP status, IP (to verify it is really that crawler) | — |
| Visitor from an AI assistant | time, assistant domain (e.g. `chatgpt.com`), path, user agent | IP, full referrer, cookies, session |
| Every other page view | a daily count, used for "share of traffic from AI" | anything about the request |

## Requirements

PHP 8.2+, Laravel 11, 12 or 13.

## Installation

> **Until the packages are on Packagist**, add both GitHub repositories to your app's `composer.json` first:
>
> ```json
> "repositories": [
>     { "type": "vcs", "url": "https://github.com/survelium/agent-analytics-laravel" },
>     { "type": "vcs", "url": "https://github.com/survelium/agent-analytics-php" }
> ]
> ```
>
> and require the development line: `composer require survelium/agent-analytics-laravel:^0.1@dev`.

```bash
composer require survelium/agent-analytics-laravel
```

Add your ingest key (Survelium → AI traffic → Integration) to `.env`:

```dotenv
SURVELIUM_INGEST_KEY=svk_...
```

Check it works:

```bash
php artisan survelium:flush --force   # sends now; expects "Survelium answered 202"
php artisan survelium:status
```

That is all. The middleware is added to the `web` group automatically and does its work in `terminate()`,
after the response has been sent. Without a key nothing is recorded, so local and CI stay quiet.

### Before you go live, check

- **Behind Cloudflare or a load balancer:** configure Laravel's trusted proxies (see [Configuration](#configuration)),
  or every crawler arrives with the proxy's IP and Survelium cannot verify it.
- **The scheduler runs** (`* * * * * php artisan schedule:run` in cron). Without it data still goes out, but only
  when new visits arrive.
- **A full-page cache in front of PHP** (Varnish, LiteSpeed, Cloudflare "cache everything", `spatie/laravel-responsecache`
  served by the web server) means cached pages never reach Laravel, so crawlers reading them are not seen. Exclude
  AI crawler user agents from that cache.

## How it sends

- Hits are buffered in `storage/app/survelium`. Every 50 items or 60 seconds a `FlushAgentAnalytics` job is
  queued (at most one a minute). On the `sync` queue it runs after the response, so visitors never wait.
- `survelium:flush` is scheduled every five minutes, so the buffer and the daily total also go out on quiet
  days. This needs the Laravel scheduler (`schedule:run` in cron) to be running.
- If Survelium is unreachable the data stays buffered and is retried with back-off; a revoked key or a plan
  without AI traffic pauses sending without losing data.

Only page views are tracked: `GET`/`HEAD` requests that are not XHR, Livewire or prefetch requests.

## Configuration

```bash
php artisan vendor:publish --tag="survelium-config"
```

| Option | Default | |
|---|---|---|
| `ingest_key` | `SURVELIUM_INGEST_KEY` | Nothing happens while empty. |
| `enabled` | `SURVELIUM_ENABLED`, `true` | |
| `track_referrals` | `true` | `false` = crawlers only. |
| `middleware_groups` | `['web']` | Set `[]` and add `TrackAgentAnalytics` yourself for full control. |
| `deny_paths` | Telescope, Horizon, Pulse, Debugbar, `/up` | Path prefixes neither reported nor counted. |
| `storage_path` | `storage/app/survelium` | |
| `queue.connection`, `queue.name` | app default | `SURVELIUM_QUEUE_CONNECTION`, `SURVELIUM_QUEUE`. |
| `schedule` | `true` | Registers `survelium:flush` every five minutes. |

Behind a load balancer or Cloudflare, configure Laravel's
[trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies) so `$request->ip()` returns the
crawler's real IP; otherwise Survelium cannot verify it.

If you run several web servers, each keeps its own buffer and daily count. Crawler hits add up correctly, but the
daily total is taken from one server at a time, so the AI share reads higher than it is.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Security

Please report vulnerabilities through [GitHub security advisories](../../security/advisories/new), not in issues.

## License

The MIT License (MIT). See [License File](LICENSE.md).
