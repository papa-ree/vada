<?php

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Jobs\SendVadaMessage;
use Bale\Vada\Livewire\SharedComponents\Chat\Inbox;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\PhoneIndex;
use Bale\Wara\Models\WaraSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Support.php';

beforeEach(function () {
    TenantManager::clear();
    Cache::flush();
    config(['vada.index_key' => 'kunci-index-vada-test']);
});

afterEach(function () {
    Auth::logout();
    TenantManager::clear();
});

function vadaInboxConversation(): VadaConversation
{
    return VadaConversation::create([
        'contact_phone' => '628123456789',
        'contact_phone_hash' => PhoneIndex::make('628123456789'),
        'last_message_at' => now(),
        'unread_count' => 0,
        'status' => 'open',
    ]);
}

it('mengirim teks dari parameter walau properti reply sudah dikosongkan', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaInboxSession($conn);
    $conversation = vadaInboxConversation();

    Queue::fake();

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id)
        ->call('sendReply', 'halo dunia')
        ->assertSet('reply', '')
        ->assertNotDispatched('toast');

    $message = VadaMessage::query()->first();

    expect($message->body)->toBe('halo dunia')
        ->and($message->status)->toBe('queued')
        ->and($message->direction)->toBe('out');

    Queue::assertPushed(SendVadaMessage::class);
});

it('memuat gambar chat secara lazy', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaInboxSession($conn);
    $conversation = vadaInboxConversation();

    VadaMessage::create([
        'conversation_id' => $conversation->id,
        'direction' => 'in',
        'body' => '',
        'status' => 'received',
        'media_type' => 'image',
        'media_path' => 'vada/'.VADA_TEST_BALE.'/x/foto.jpg',
        'media_name' => 'foto.jpg',
        'media_mime' => 'image/jpeg',
        'media_size' => 10,
        'media_status' => VadaMessage::MEDIA_READY,
    ]);

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id)
        ->assertSee('loading="lazy"', false)
        ->assertSee('foto.jpg');
});

it('hanya merender 30 pesan terbaru lalu menambah lewat chat lama', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaInboxSession($conn);
    $conversation = vadaInboxConversation();

    foreach (range(1, 35) as $i) {
        $message = VadaMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'body' => sprintf('pesan-%02d', $i),
            'status' => 'received',
        ]);

        $message->forceFill(['created_at' => now()->subMinutes(35 - $i)])->save();
    }

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id)
        ->assertSee('pesan-35')
        ->assertDontSee('pesan-01')
        ->assertSee(__('Tampilkan chat lama'))
        ->call('loadOlder')
        ->assertSee('pesan-01')
        ->assertDontSee(__('Tampilkan chat lama'));
});

it('menolak pesan kosong tanpa lampiran', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaInboxSession($conn);
    $conversation = vadaInboxConversation();

    Queue::fake();

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id)
        ->call('sendReply', '')
        ->assertHasErrors('reply');

    Queue::assertNothingPushed();
});

it('menyimpan target balasan dari percakapan yang sama', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaInboxSession($conn);
    $conversation = vadaInboxConversation();

    $target = VadaMessage::create([
        'conversation_id' => $conversation->id,
        'direction' => 'in',
        'wara_message_id' => 'wamid-target',
        'body' => 'pesan asal',
        'status' => 'received',
    ]);

    Queue::fake();

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id)
        ->call('sendReply', 'balasan', $target->id)
        ->assertSee('pesan asal');

    expect(VadaMessage::query()->where('direction', 'out')->first()->reply_to_id)->toBe($target->id);
});

it('menolak target balasan dari percakapan lain', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaInboxSession($conn);
    $conversation = vadaInboxConversation();

    $other = VadaConversation::create([
        'contact_phone' => '628987654321',
        'contact_phone_hash' => PhoneIndex::make('628987654321'),
        'last_message_at' => now(),
        'unread_count' => 0,
        'status' => 'open',
    ]);

    $foreign = VadaMessage::create([
        'conversation_id' => $other->id,
        'direction' => 'in',
        'body' => 'pesan lain',
        'status' => 'received',
    ]);

    Queue::fake();

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id)
        ->call('sendReply', 'balasan', $foreign->id);

    expect(VadaMessage::query()->where('direction', 'out')->first()->reply_to_id)->toBeNull();
});

it('meneruskan reply_message_id ke gateway', function () {
    $conn = vadaTenant();

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

    $conversationId = (string) Str::uuid();
    DB::connection($conn)->table('vada_conversations')->insert([
        'id' => $conversationId,
        'contact_phone' => '628123456789',
        'contact_phone_hash' => str_repeat('c', 64),
        'unread_count' => 0,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $targetId = (string) Str::uuid();
    DB::connection($conn)->table('vada_messages')->insert([
        'id' => $targetId,
        'conversation_id' => $conversationId,
        'direction' => 'in',
        'wara_message_id' => 'wamid-target',
        'body' => '',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messageId = (string) Str::uuid();
    DB::connection($conn)->table('vada_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'direction' => 'out',
        'reply_to_id' => $targetId,
        'body' => '',
        'status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake(['gateway.test/*' => Http::response(['message_id' => 'wamid-out'], 200)]);

    (new SendVadaMessage(VADA_TEST_BALE, $messageId, 'dev-a', '628123456789', 'balasan'))->handle();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/message')
        && $request['reply_message_id'] === 'wamid-target');

    expect(DB::connection($conn)->table('vada_messages')->where('id', $messageId)->value('status'))
        ->toBe('sent');
});
