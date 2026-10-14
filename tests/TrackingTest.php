<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Survelium\AgentAnalytics\AgentAnalytics;
use Survelium\AgentAnalytics\Transport\HttpResponse;
use Survelium\LaravelAgentAnalytics\Jobs\FlushAgentAnalytics;

const GPTBOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';
const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

beforeEach(function () {
    Queue::fake();

    Route::middleware('web')->group(function () {
        Route::get('/pricing', fn () => 'pricing');
        Route::get('/missing', fn () => abort(404));
        Route::post('/contact', fn () => 'sent');
        Route::get('/telescope/requests', fn () => 'telescope');
    });

    Route::get('/api/ping', fn () => 'pong');
});

function buffered(): array
{
    return array_values(app(AgentAnalytics::class)->storage->pending(100));
}

function totalToday(): int
{
    return app(AgentAnalytics::class)->storage->totals(1)[gmdate('Y-m-d')] ?? 0;
}

describe('the middleware', function () {
    it('records an AI crawler with path, status and IP after the response', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '20.171.206.10'])->get('/missing?ref=1', ['User-Agent' => GPTBOT_UA])->assertNotFound();

        expect(buffered())->toHaveCount(1)
            ->and(buffered()[0])->toMatchArray([
                'kind' => 'event',
                'ua' => GPTBOT_UA,
                'path' => '/missing?ref=1',
                'status' => 404,
                'ip' => '20.171.206.10',
            ])
            ->and(totalToday())->toBe(1);
    });

    it('records a visitor sent by an AI assistant without their IP', function () {
        $this->get('/pricing', ['User-Agent' => CHROME_UA, 'Referer' => 'https://chatgpt.com/']);

        expect(buffered()[0])->toMatchArray(['kind' => 'referral', 'source' => 'chatgpt.com', 'path' => '/pricing'])
            ->and(buffered()[0])->not->toHaveKey('ip');
    });

    it('only counts ordinary visitors', function () {
        $this->get('/pricing', ['User-Agent' => CHROME_UA, 'Referer' => 'https://www.google.com/']);

        expect(buffered())->toBe([])->and(totalToday())->toBe(1);
    });

    it('ignores form posts, XHR, Livewire and prefetches', function () {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/contact', [], ['User-Agent' => GPTBOT_UA]);
        $this->get('/pricing', ['User-Agent' => GPTBOT_UA, 'X-Requested-With' => 'XMLHttpRequest']);
        $this->get('/pricing', ['User-Agent' => GPTBOT_UA, 'X-Livewire' => 'true']);
        $this->get('/pricing', ['User-Agent' => GPTBOT_UA, 'Sec-Purpose' => 'prefetch']);

        expect(buffered())->toBe([])->and(totalToday())->toBe(0);
    });

    it('skips denied paths and routes outside the configured groups', function () {
        $this->get('/telescope/requests', ['User-Agent' => GPTBOT_UA]);
        $this->get('/api/ping', ['User-Agent' => GPTBOT_UA]);

        expect(buffered())->toBe([])->and(totalToday())->toBe(0);
    });

    it('does nothing without an ingest key', function () {
        config()->set('survelium.ingest_key', null);

        $this->get('/pricing', ['User-Agent' => GPTBOT_UA]);

        expect(is_dir($this->storagePath))->toBeFalse();
    });

    it('does nothing when disabled', function () {
        config()->set('survelium.enabled', false);

        $this->get('/pricing', ['User-Agent' => GPTBOT_UA]);

        expect(is_dir($this->storagePath))->toBeFalse();
    });

    it('queues a flush once a batch is due', function () {
        app(AgentAnalytics::class)->storage->putState(['totals_sent_at' => time()]);
        app(AgentAnalytics::class)->refreshSignatures();

        $this->get('/pricing', ['User-Agent' => CHROME_UA]);
        Queue::assertNotPushed(FlushAgentAnalytics::class);

        config()->set('survelium.batch_size', 1);
        app()->forgetInstance(AgentAnalytics::class);

        $this->get('/pricing', ['User-Agent' => GPTBOT_UA]);
        Queue::assertPushed(FlushAgentAnalytics::class);
    });

    it('honours the queue connection and name from config', function () {
        config()->set('survelium.queue', ['connection' => 'redis', 'name' => 'analytics']);

        $job = new FlushAgentAnalytics;

        expect($job->connection)->toBe('redis')->and($job->queue)->toBe('analytics');
    });
});

describe('flushing', function () {
    it('sends the buffer from the queued job', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '20.171.206.10'])->get('/pricing', ['User-Agent' => GPTBOT_UA]);

        app()->call([new FlushAgentAnalytics, 'handle']);

        $ingest = collect($this->transport->requests)->firstWhere('method', 'POST');

        expect($ingest['url'])->toBe('https://survelium.test/api/v1/ingest/crawlers')
            ->and($ingest['headers']['Authorization'])->toBe('Bearer svk_test')
            ->and(json_decode($ingest['body'], true)['events'][0]['path'])->toBe('/pricing')
            ->and(buffered())->toBe([]);
    });

    it('reports a successful forced flush', function () {
        $this->get('/pricing', ['User-Agent' => GPTBOT_UA]);

        $this->artisan('survelium:flush --force')
            ->expectsOutputToContain('202')
            ->assertSuccessful();
    });

    it('reports a rejected key and keeps the data', function () {
        $this->get('/pricing', ['User-Agent' => GPTBOT_UA]);
        $this->transport->push(new HttpResponse(304))->push(new HttpResponse(401));

        $this->artisan('survelium:flush --force')
            ->expectsOutputToContain('invalid_key')
            ->assertFailed();

        expect(buffered())->toHaveCount(1);
    });

    it('says when nothing is due', function () {
        app(AgentAnalytics::class)->storage->putState(['totals_sent_at' => time()]);
        app(AgentAnalytics::class)->refreshSignatures();

        $this->artisan('survelium:flush')->expectsOutputToContain('Nothing due yet')->assertSuccessful();
    });

    it('warns when not configured', function () {
        config()->set('survelium.ingest_key', '');

        $this->artisan('survelium:flush')->expectsOutputToContain('SURVELIUM_INGEST_KEY')->assertSuccessful();

        expect($this->transport->requests)->toBe([]);
    });

    it('is scheduled every five minutes', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'survelium:flush'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
    });
});

it('shows the buffer and last send in survelium:status', function () {
    $this->get('/pricing', ['User-Agent' => GPTBOT_UA]);

    $this->artisan('survelium:status')
        ->expectsOutputToContain('svk_test')
        ->expectsOutputToContain('Buffered items')
        ->assertSuccessful();
});
