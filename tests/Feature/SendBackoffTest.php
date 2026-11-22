<?php

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Jobs\SendVadaMessage;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Support\SendPacer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support.php';

beforeEach(function () {
    TenantManager::clear();
    Cache::flush();
    config([
        'vada.index_key' => 'kunci-index-vada-test',
        'wara.endpoint' => 'http://gateway.test',
        'wara.api_key' => 'user:pass',
        'wara.device_backoff_seconds' => 300,
    ]);
});

afterEach(function () {
    TenantManager::clear();
});

/**
 * Satu pesan keluar berstatus `queued` di koneksi tenant aktif.
 */
function vadaQueuedMessage(string $conn): string
{
    $conversationId = (string) Str::uuid();
    $messageId = (string) Str::uuid();

    DB::connection($conn)->table('vada_conversations')->insert([
        'id' => $conversationId,
        'contact_phone' => '628123456789',
        'contact_phone_hash' => str_repeat('c', 64),
        'unread_count' => 0,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection($conn)->table('vada_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'direction' => 'out',
        'body' => 'halo',
        'status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    WaraSession::create([
        'device_id' => 'dev-a',
        'jid' => '628000000001@s.whatsapp.net',
        'display_name' => 'Device Vada',
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ]);

    return $messageId;
}

it('menunda pengiriman selama device backoff, bukan menghabiskan tries', function () {
    $conn = vadaTenant();
    $messageId = vadaQueuedMessage($conn);

    app(SendPacer::class)->markDeviceBackoff('dev-a');

    Http::preventStrayRequests();

    (new SendVadaMessage(VADA_TEST_BALE, $messageId, 'dev-a', '628123456789', 'halo'))->handle();

    // `release()` adalah no-op di luar worker, jadi yang bisa diperiksa:
    // pesan tidak dicap gagal dan gateway tidak pernah dipanggil.
    expect(DB::connection($conn)->table('vada_messages')->where('id', $messageId)->value('status'))
        ->toBe('queued');

    Http::assertNothingSent();
});

it('menandai gagal saat backoff terjadi pada percobaan terakhir', function () {
    $conn = vadaTenant();
    $messageId = vadaQueuedMessage($conn);

    app(SendPacer::class)->markDeviceBackoff('dev-a');

    Http::preventStrayRequests();

    $job = new SendVadaMessage(VADA_TEST_BALE, $messageId, 'dev-a', '628123456789', 'halo');
    $job->tries = 1;
    $job->handle();

    $row = DB::connection($conn)->table('vada_messages')->where('id', $messageId)->first();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toContain('backoff');

    Http::assertNothingSent();
});
