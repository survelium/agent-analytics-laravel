<?php

use Survelium\AgentAnalytics\Config;

return [

    /*
     * The ingest key from Survelium → AI traffic → Integration. Nothing is
     * recorded or sent while it is empty, so local and CI stay quiet.
     */
    'ingest_key' => env('SURVELIUM_INGEST_KEY'),

    'enabled' => env('SURVELIUM_ENABLED', true),

    'endpoint' => env('SURVELIUM_ENDPOINT', Config::DEFAULT_ENDPOINT),

    /*
     * Also report people who arrive from an AI assistant (ChatGPT, Perplexity,
     * Claude, Gemini, Copilot). Only the assistant's domain and the landing
     * path are sent, never the visitor's IP.
     */
    'track_referrals' => env('SURVELIUM_TRACK_REFERRALS', true),

    /*
     * The tracking middleware is appended to these middleware groups.
     * Set to [] and add \Survelium\LaravelAgentAnalytics\Http\Middleware\TrackAgentAnalytics
     * yourself if you need it elsewhere.
     */
    'middleware_groups' => ['web'],

    /*
     * Path prefixes that are neither reported nor counted in the daily total.
     */
    'deny_paths' => [
        '/telescope',
        '/horizon',
        '/pulse',
        '/_debugbar',
        '/up',
    ],

    /*
     * Folder for the local buffer, daily counters and the cached bot list.
     */
    'storage_path' => storage_path('app/survelium'),

    /*
     * Batches are sent by a queued job, so the request never waits. On the
     * sync queue the job runs after the response has been sent.
     */
    'queue' => [
        'connection' => env('SURVELIUM_QUEUE_CONNECTION'),
        'name' => env('SURVELIUM_QUEUE'),
    ],

    /*
     * Run `survelium:flush` every five minutes from the scheduler, so buffered
     * data and the daily total also go out on quiet days.
     */
    'schedule' => true,

    'batch_size' => 50,

    'flush_interval_seconds' => 60,

];
