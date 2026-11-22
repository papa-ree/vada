<?php

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Jobs\SendVadaMessage;
use Bale\Vada\Listeners\StoreIncomingVadaMessage;
use Bale\Vada\Listeners\StoreOutgoingVadaMessage;
use Bale\Vada\Models\VadaConversation;
use Bale\Wara\Events\WaraMessageSent;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support.php';

/*
|--------------------------------------------------------------------------
| Isolasi tenant bale/vada
|--------------------------------------------------------------------------
|
| Dua hal yang tidak boleh salah: pesan masuk hanya boleh mendarat di database
| tenant pemilik device, dan balasan hanya boleh keluar dari device tenant itu.
|
| Koneksi tenant di sini adalah sqlite in-memory yang di-resolve LEBIH DULU,
| sehingga `TenantManager::initializeFromBaleUuid()` menimpa config-nya dengan
| mysql tapi `DB::connection()` tetap mengembalikan objek sqlite yang sudah
| ter-cache. Tanpa trik ini, listener dan job tidak bisa diuji tanpa MySQL.
|
*/

beforeEach(function () {
    TenantManager::clear();
    Cache::flush();
    config(['vada.index_key' => 'kunci-index-vada-test']);
});

it('menyimpan pesan masuk ke database tenant, bukan landlord', function () {
    $conn = vadaTenant();
    vadaChannel();

    (new StoreIncomingVadaMessage)->handle(vadaIncoming());

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(1)
        ->and(DB::connection($conn)->table('vada_conversations')->count())->toBe(1)
        ->and(Schema::hasTable('vada_messages'))->toBeFalse();
});

it('tidak menggandakan pesan dengan wara_message_id yang sama', function () {
    $conn = vadaTenant();
    vadaChannel();

    $listener = new StoreIncomingVadaMessage;
    $listener->handle(vadaIncoming());
    $listener->handle(vadaIncoming());

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(1)
        ->and(DB::connection($conn)->table('vada_conversations')->value('unread_count'))->toBe(1);
});

it('menautkan kutipan ke pesan yang dikutip di percakapan yang sama', function () {
    $conn = vadaTenant();
    vadaChannel();

    $listener = new StoreIncomingVadaMessage;
    $listener->handle(vadaIncoming(messageId: 'msg-1', body: 'halo'));
    $listener->handle(vadaIncoming(messageId: 'msg-2', body: 'balasan', repliedToId: 'msg-1', quotedBody: 'halo'));

    $quoted = DB::connection($conn)->table('vada_messages')->where('wara_message_id', 'msg-1')->value('id');
    $reply = DB::connection($conn)->table('vada_messages')->where('wara_message_id', 'msg-2')->value('reply_to_id');

    expect($reply)->toBe($quoted);
});

it('tidak menautkan kutipan yang pesannya tidak tersimpan lokal', function () {
    $conn = vadaTenant();
    vadaChannel();

    (new StoreIncomingVadaMessage)->handle(vadaIncoming(repliedToId: 'msg-lama', quotedBody: 'halo'));

    expect(DB::connection($conn)->table('vada_messages')->value('reply_to_id'))->toBeNull();
});

it('mengabaikan device yang tidak punya kanal', function () {
    $conn = vadaTenant();
    vadaChannel('dev-a');

    (new StoreIncomingVadaMessage)->handle(vadaIncoming('dev-b'));

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(0);
});

it('mengabaikan kanal nonaktif', function () {
    $conn = vadaTenant();
    vadaChannel('dev-a', active: false);

    (new StoreIncomingVadaMessage)->handle(vadaIncoming());

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(0);
});

it('mengabaikan pesan grup', function () {
    $conn = vadaTenant();
    vadaChannel();

    (new StoreIncomingVadaMessage)->handle(vadaIncoming(chatId: '120363@g.us'));

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(0);
});

it('mengirim balasan lewat device kanal tenant', function () {
    $conn = vadaTenant();
    vadaChannel('dev-a');

    config([
        'wara.endpoint' => 'http://gateway.test',
        'wara.api_key' => 'user:pass',
        'wara.default_device_id' => '',
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

    $messageId = '33333333-3333-3333-3333-333333333333';
    $conversationId = '44444444-4444-4444-4444-444444444444';

    DB::connection($conn)->table('vada_conversations')->insert([
        'id' => $conversationId,
        'contact_phone' => '628123456789',
        'contact_phone_hash' => str_repeat('a', 64),
        'unread_count' => 0,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection($conn)->table('vada_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'direction' => 'out',
        'body' => 'balasan',
        'status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake(['gateway.test/*' => Http::response(['message_id' => 'wamid-1'], 200)]);

    (new SendVadaMessage(VADA_TEST_BALE, $messageId, 'dev-a', '628123456789', 'balasan'))
        ->handle();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Device-Id', 'dev-a')
        && str_ends_with($request->url(), '/send/message'));

    expect(DB::connection($conn)->table('vada_messages')->value('status'))->toBe('sent');
});

it('menaruh pesan yang dikirim dari HP di percakapan lawan bicara sebagai pesan keluar', function () {
    $conn = vadaTenant();
    vadaChannel();

    Queue::fake();

    (new StoreIncomingVadaMessage)->handle(vadaIncoming(
        messageId: 'echo-1',
        phone: '628000000001',
        body: 'balasan dari hp',
        chatId: '628123456789@s.whatsapp.net',
        fromMe: true,
    ));

    $row = DB::connection($conn)->table('vada_messages')->first();

    TenantManager::initializeFromBaleUuid(VADA_TEST_BALE);
    $conversation = VadaConversation::query()->first();
    TenantManager::clear();

    expect($row->direction)->toBe('out')
        ->and($row->status)->toBe('sent')
        ->and($conversation->contact_phone)->toBe('628123456789')
        ->and($conversation->unread_count)->toBe(0);

    Queue::assertNothingPushed();
});

it('mengabaikan echo tanpa teks dan chat non-telepon', function () {
    $conn = vadaTenant();
    vadaChannel();

    $listener = new StoreIncomingVadaMessage;

    $listener->handle(vadaIncoming(
        messageId: 'echo-media',
        phone: '628000000001',
        body: '',
        chatId: '628123456789@s.whatsapp.net',
        media: ['type' => 'image', 'filename' => 'foto.jpg', 'caption' => null, 'path' => null, 'url' => null],
        fromMe: true,
    ));

    $listener->handle(vadaIncoming(
        messageId: 'bot-1',
        body: 'halo bot',
        chatId: '1807055946647698@bot',
    ));

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(0)
        ->and(DB::connection($conn)->table('vada_conversations')->count())->toBe(0);
});

it('membuang sufiks device pada chat_id saat menentukan percakapan', function () {
    $conn = vadaTenant();
    vadaChannel();

    (new StoreIncomingVadaMessage)->handle(vadaIncoming(
        chatId: '628123456789:12@s.whatsapp.net',
    ));

    TenantManager::initializeFromBaleUuid(VADA_TEST_BALE);
    $conversation = VadaConversation::query()->first();
    TenantManager::clear();

    expect($conversation->contact_phone)->toBe('628123456789')
        ->and(DB::connection($conn)->table('vada_messages')->count())->toBe(1);
});

it('mencatat notifikasi package lain ke riwayat chat tenant pemilik device', function () {
    $conn = vadaTenant();
    vadaChannel();

    WaraLog::create([
        'direction' => 'out',
        'event' => 'message',
        'device_id' => 'dev-a',
        'phone' => '628123456789',
        'message_id' => 'notif-1',
        'body' => 'Sidulur: tiket SID-1 telah dibuat.',
        'status' => 'accepted',
    ]);

    // Request tenant yang sedang berjalan tidak boleh kehilangan koneksinya.
    TenantManager::initializeFromBaleUuid(VADA_TEST_BALE);

    WaraMessageSent::dispatch('628123456789', 'dev-a', 'notif-1', 12);

    $row = DB::connection($conn)->table('vada_messages')->first();

    expect($row)->not->toBeNull()
        ->and($row->direction)->toBe('out')
        ->and($row->status)->toBe('sent')
        ->and(DB::connection($conn)->table('vada_conversations')->value('unread_count'))->toBe(0)
        ->and(TenantManager::getActiveConnection())->toBe(TenantManager::connectionName(VADA_TEST_BALE));

    TenantManager::clear();
});

it('tidak menggandakan pesan yang dikirim lewat SendVadaMessage', function () {
    $conn = vadaTenant();
    vadaChannel();

    WaraLog::create([
        'direction' => 'out',
        'event' => 'message',
        'device_id' => 'dev-a',
        'phone' => '628123456789',
        'message_id' => 'balasan-1',
        'body' => 'balasan admin',
        'status' => 'accepted',
    ]);

    (new StoreOutgoingVadaMessage)->handle(
        new WaraMessageSent('628123456789', 'dev-a', 'balasan-1', 12, SendVadaMessage::class)
    );

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(0);
});
