<?php

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Jobs\FetchVadaMedia;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support.php';

beforeEach(function () {
    TenantManager::clear();
    Cache::flush();
    config(['vada.index_key' => 'kunci-index-vada-test']);
    config(['wara.endpoint' => 'http://gateway.test', 'wara.api_key' => 'user:pass']);

    Http::preventStrayRequests();
    Queue::fake();
});

function fakeHistoryGateway(): void
{
    Http::fake([
        'gateway.test/chats*' => Http::response([
            'results' => [
                'data' => [
                    ['jid' => '628123456789@s.whatsapp.net', 'name' => 'Budi'],
                    ['jid' => '120363012345678901@g.us', 'name' => 'Grup RT'],
                ],
                'pagination' => ['limit' => 100, 'offset' => 0, 'total' => 2],
            ],
        ], 200),
        'gateway.test/chat/*/messages*' => Http::response([
            'results' => [
                'data' => [
                    ['id' => 'm1', 'content' => 'halo', 'timestamp' => '2026-11-18T10:00:00Z', 'is_from_me' => false],
                    ['id' => 'm2', 'content' => 'balasan', 'timestamp' => '2026-11-18T10:05:00Z', 'is_from_me' => true],
                    ['id' => 'm3', 'content' => '', 'timestamp' => '2026-11-18T10:06:00Z', 'is_from_me' => false, 'media_type' => 'image', 'filename' => 'foto.jpg'],
                    ['id' => 'm4', 'content' => '', 'timestamp' => '2026-11-18T10:07:00Z', 'is_from_me' => true, 'media_type' => 'image'],
                    ['id' => 'm5', 'content' => '', 'timestamp' => '2026-11-18T10:08:00Z', 'is_from_me' => false, 'media_type' => 'call'],
                ],
                'pagination' => ['limit' => 100, 'offset' => 0, 'total' => 5],
            ],
        ], 200),
    ]);
}

it('mengimpor riwayat chat ke percakapan tenant', function () {
    $conn = vadaTenant();
    vadaChannel();
    fakeHistoryGateway();

    $this->artisan('vada:sync-history', ['--tenant' => 'tenant-vada'])->assertSuccessful();

    $rows = DB::connection($conn)->table('vada_messages')->orderBy('created_at')->get();

    expect($rows)->toHaveCount(4)
        ->and($rows[0]->direction)->toBe('in')
        ->and($rows[0]->status)->toBe('received')
        ->and($rows[1]->direction)->toBe('out')
        ->and($rows[1]->status)->toBe('sent')
        ->and($rows[0]->created_at)->toBe('2026-11-18 10:00:00')
        ->and($rows[2]->media_type)->toBe('image')
        ->and($rows[2]->media_status)->toBe('pending')
        ->and($rows[3]->media_status)->toBe('failed')
        ->and(DB::connection($conn)->table('vada_conversations')->count())->toBe(1)
        ->and(DB::connection($conn)->table('vada_conversations')->value('unread_count'))->toBe(0)
        ->and(DB::connection($conn)->table('vada_conversations')->value('last_message_at'))->toBe('2026-11-18 10:07:00');

    Queue::assertPushed(FetchVadaMedia::class, 1);
});

it('tidak menggandakan pesan saat dijalankan ulang', function () {
    $conn = vadaTenant();
    vadaChannel();
    fakeHistoryGateway();

    $this->artisan('vada:sync-history', ['--tenant' => 'tenant-vada'])->assertSuccessful();
    $this->artisan('vada:sync-history', ['--tenant' => 'tenant-vada'])->assertSuccessful();

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(4);

    Queue::assertPushed(FetchVadaMedia::class, 1);
});

it('tidak menulis apa pun saat dry-run', function () {
    $conn = vadaTenant();
    vadaChannel();
    fakeHistoryGateway();

    $this->artisan('vada:sync-history', ['--tenant' => 'tenant-vada', '--dry-run' => true])
        ->expectsOutputToContain('Akan diimpor: 4 pesan')
        ->assertSuccessful();

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(0)
        ->and(DB::connection($conn)->table('vada_conversations')->count())->toBe(0);

    Queue::assertNothingPushed();
});
