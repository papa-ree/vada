<?php

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Listeners\UpdateVadaMessageStatus;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\PhoneIndex;
use Bale\Wara\Events\WaraMessageAck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support.php';

beforeEach(function () {
    TenantManager::clear();
    Cache::flush();
    config(['vada.index_key' => 'kunci-index-vada-test']);
});

/**
 * Percakapan + satu pesan di database tenant.
 */
function vadaReceiptRow(string $conn, array $message = [], int $unread = 0): string
{
    $conversationId = (string) Str::uuid();

    DB::connection($conn)->table('vada_conversations')->insert([
        'id' => $conversationId,
        'contact_phone' => '628123456789',
        'contact_phone_hash' => PhoneIndex::make('628123456789'),
        'unread_count' => $unread,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection($conn)->table('vada_messages')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'direction' => 'out',
        'wara_message_id' => 'msg-1',
        'body' => '',
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ], $message));

    return $conversationId;
}

function vadaAck(array $ids = ['msg-1'], ?string $receiptType = 'delivered', ?string $phone = '628123456789'): WaraMessageAck
{
    return new WaraMessageAck('dev-a', $ids, $receiptType, '628123456789@s.whatsapp.net', $phone);
}

it('menaikkan status keluar dan tidak menurunkannya saat ack lama datang', function () {
    $conn = vadaTenant();
    vadaChannel();
    vadaReceiptRow($conn);

    $listener = new UpdateVadaMessageStatus;

    $listener->handle(vadaAck());
    expect(DB::connection($conn)->table('vada_messages')->value('status'))->toBe('delivered');

    $listener->handle(vadaAck(receiptType: 'read'));
    $listener->handle(vadaAck(receiptType: 'delivered'));

    expect(DB::connection($conn)->table('vada_messages')->value('status'))->toBe('read');
});

it('tidak menyentuh pesan masuk, device lain, atau tenant lain', function () {
    $conn = vadaTenant();
    vadaChannel();
    vadaReceiptRow($conn, ['direction' => 'in', 'status' => 'received']);

    $listener = new UpdateVadaMessageStatus;
    $listener->handle(vadaAck());
    $listener->handle(new WaraMessageAck('dev-lain', ['msg-1'], 'read'));

    expect(DB::connection($conn)->table('vada_messages')->value('status'))->toBe('received');
});

it('tidak membangkitkan tombstone yang sudah dihapus untuk semua', function () {
    $conn = vadaTenant();
    vadaChannel();
    vadaReceiptRow($conn, ['status' => VadaMessage::STATUS_DELETED]);

    (new UpdateVadaMessageStatus)->handle(vadaAck(receiptType: 'read'));

    expect(DB::connection($conn)->table('vada_messages')->value('status'))->toBe(VadaMessage::STATUS_DELETED);
});

it('mengosongkan unread saat akun membaca dari perangkat lain', function () {
    $conn = vadaTenant();
    vadaChannel();
    vadaReceiptRow($conn, ['direction' => 'in', 'status' => 'received'], unread: 3);

    (new UpdateVadaMessageStatus)->handle(vadaAck(receiptType: 'read-self'));

    expect(DB::connection($conn)->table('vada_conversations')->value('unread_count'))->toBe(0);
});
