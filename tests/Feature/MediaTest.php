<?php

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Jobs\FetchVadaMedia;
use Bale\Vada\Jobs\SendVadaMessage;
use Bale\Vada\Listeners\StoreIncomingVadaMessage;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\Media;
use Bale\Wara\Models\WaraSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support.php';

beforeEach(function () {
    TenantManager::clear();
    Cache::flush();
    config(['vada.index_key' => 'kunci-index-vada-test']);
});

/**
 * Baris percakapan + pesan di database tenant yang aktif.
 *
 * @param  array<string, mixed>  $extra
 * @return array{0: string, 1: string} conversation id, message id
 */
function vadaMediaMessage(string $conn, array $extra = []): array
{
    $conversationId = (string) Str::uuid();
    $messageId = (string) Str::uuid();

    DB::connection($conn)->table('vada_conversations')->insert([
        'id' => $conversationId,
        'contact_phone' => '628123456789',
        'contact_phone_hash' => str_repeat('b', 64),
        'unread_count' => 0,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection($conn)->table('vada_messages')->insert(array_merge([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'direction' => 'in',
        'wara_message_id' => 'msg-1',
        'body' => '',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));

    return [$conversationId, $messageId];
}

it('menyimpan media masuk tanpa caption sebagai baris pending', function () {
    Queue::fake();

    $conn = vadaTenant();
    vadaChannel();

    (new StoreIncomingVadaMessage)->handle(vadaIncoming(
        body: '',
        media: ['type' => 'image', 'filename' => 'foto.jpg', 'caption' => null, 'path' => null, 'url' => null],
    ));

    $row = DB::connection($conn)->table('vada_messages')->first();

    expect($row->media_type)->toBe('image')
        ->and($row->media_status)->toBe('pending');

    Queue::assertPushed(FetchVadaMedia::class);
});

it('menandai video sebagai media yang belum didukung', function () {
    Queue::fake();

    $conn = vadaTenant();
    vadaChannel();

    (new StoreIncomingVadaMessage)->handle(vadaIncoming(
        body: '',
        media: ['type' => 'video', 'filename' => 'klip.mp4', 'caption' => null, 'path' => null, 'url' => null],
    ));

    expect(DB::connection($conn)->table('vada_messages')->value('media_status'))
        ->toBe('failed');

    Queue::assertNotPushed(FetchVadaMedia::class);
});

it('mengunduh media masuk lewat gateway dan menyimpannya di disk vada', function () {
    $conn = vadaTenant();
    Storage::fake('vada');

    config(['wara.endpoint' => 'http://gateway.test', 'wara.api_key' => 'user:pass']);

    Http::preventStrayRequests();
    Http::fake([
        'gateway.test/message/msg-1/download*' => Http::response(['results' => [
            'media_type' => 'image',
            'filename' => 'foto.jpg',
            'file_url' => 'http://gateway.test/statics/media/628123/2026-11-19/foto.jpg',
            'file_size' => 5,
        ]], 200),
        'gateway.test/statics/*' => Http::response('hello', 200),
    ]);

    [, $messageId] = vadaMediaMessage($conn, [
        'media_type' => 'image',
        'media_status' => 'pending',
    ]);

    (new FetchVadaMedia(VADA_TEST_BALE, $messageId, 'dev-a', '628123456789', 'msg-1'))->handle();

    $row = DB::connection($conn)->table('vada_messages')->where('id', $messageId)->first();

    expect($row->media_status)->toBe('ready')
        ->and($row->media_size)->toBe(5)
        ->and($row->media_mime)->toBe('image/jpeg')
        ->and(Storage::disk('vada')->exists($row->media_path))->toBeTrue();
});

it('mengubah gambar menjadi webp dengan nama asli tetap', function () {
    Storage::fake('vada');

    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagejpeg($image);
    $contents = (string) ob_get_clean();
    imagedestroy($image);

    $stored = Media::save($contents, VADA_TEST_BALE, 'msg-1', 'Foto Besar.JPG');

    expect($stored['path'])->toEndWith('/Foto_Besar.webp')
        ->and($stored['name'])->toBe('Foto Besar.JPG')
        ->and($stored['mime'])->toBe('image/webp')
        ->and($stored['size'])->toBeGreaterThan(0)
        ->and(Storage::disk('vada')->exists($stored['path']))->toBeTrue();
});

it('memakai ekstensi berkas tersimpan pada URL bertanda tangan', function () {
    $message = new VadaMessage(['media_path' => 'vada/x/foto.webp', 'media_name' => 'foto.jpg']);
    $message->id = (string) Str::uuid();

    expect((string) Media::signedUrl($message, VADA_TEST_BALE))->toContain('/signed/foto.webp');
});

it('mengirim header cache saat menyajikan media', function () {
    $conn = vadaTenant();
    Storage::fake('vada');

    $user = vadaInboxSession($conn);

    [, $messageId] = vadaMediaMessage($conn, [
        'media_type' => 'image',
        'media_path' => 'vada/x/foto.webp',
        'media_name' => 'foto.webp',
        'media_mime' => 'image/webp',
        'media_size' => 3,
        'media_status' => 'ready',
    ]);

    Storage::disk('vada')->put('vada/x/foto.webp', 'abc');

    $response = $this->actingAs($user)->get(route('vada.media', $messageId));

    $response->assertOk();

    expect((string) $response->headers->get('Cache-Control'))
        ->toContain('max-age=604800');
});

it('membalas 410 saat berkas sudah tidak ada di disk', function () {
    $conn = vadaTenant();
    Storage::fake('vada');

    $user = vadaInboxSession($conn);

    [, $messageId] = vadaMediaMessage($conn, [
        'media_type' => 'image',
        'media_path' => 'vada/x/hilang.webp',
        'media_name' => 'hilang.webp',
        'media_mime' => 'image/webp',
        'media_size' => 3,
        'media_status' => 'ready',
    ]);

    $this->actingAs($user)->get(route('vada.media', $messageId))->assertStatus(410);
});

it('menerima JPEG bernama .jfif dari gateway sebagai gambar', function () {
    expect(Media::allows('image', '1795142457-d739f2fc.jfif'))->toBeTrue()
        ->and(Media::mimeFor('gambar.jfif'))->toBe('image/jpeg');
});

it('menolak jenis berkas di luar allowlist tanpa mengulang', function () {
    $conn = vadaTenant();
    Storage::fake('vada');

    config(['wara.endpoint' => 'http://gateway.test', 'wara.api_key' => 'user:pass']);

    Http::preventStrayRequests();
    Http::fake([
        'gateway.test/message/msg-1/download*' => Http::response(['results' => [
            'filename' => 'modul.exe',
            'file_url' => 'http://gateway.test/statics/media/x/modul.exe',
            'file_size' => 5,
        ]], 200),
        'gateway.test/statics/*' => Http::response('hello', 200),
    ]);

    [, $messageId] = vadaMediaMessage($conn, [
        'media_type' => 'document',
        'media_status' => 'pending',
    ]);

    (new FetchVadaMedia(VADA_TEST_BALE, $messageId, 'dev-a', '628123456789', 'msg-1'))->handle();

    expect(DB::connection($conn)->table('vada_messages')->where('id', $messageId)->value('media_status'))
        ->toBe('failed');
});

it('mengirim media keluar lewat URL bertanda tangan', function () {
    $conn = vadaTenant();
    Storage::fake('vada');

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

    [, $messageId] = vadaMediaMessage($conn, [
        'direction' => 'out',
        'status' => 'queued',
        'body' => 'caption',
        'media_type' => 'image',
        'media_path' => 'vada/'.VADA_TEST_BALE.'/'.'foto.jpg',
        'media_name' => 'foto.jpg',
        'media_mime' => 'image/jpeg',
        'media_size' => 5,
        'media_status' => 'ready',
    ]);

    Http::preventStrayRequests();
    Http::fake(['gateway.test/*' => Http::response(['message_id' => 'wamid-1'], 200)]);

    (new SendVadaMessage(VADA_TEST_BALE, $messageId, 'dev-a', '628123456789', 'caption', 'image'))->handle();

    Http::assertSent(function ($request) {
        $fields = collect($request->data())->keyBy('name');

        return str_ends_with($request->url(), '/send/image')
            && str_starts_with((string) ($request->header('Content-Type')[0] ?? ''), 'multipart/form-data')
            && str_contains((string) $fields['image_url']['contents'], '/vada/media/')
            && str_contains((string) $fields['image_url']['contents'], '/signed/foto.jpg')
            && str_contains((string) $fields['image_url']['contents'], 'signature=')
            && (string) $fields['caption']['contents'] === 'caption';
    });

    expect(DB::connection($conn)->table('vada_messages')->where('id', $messageId)->value('status'))
        ->toBe('sent');
});

it('mem purge media kedaluwarsa tapi mempertahankan baris pesan', function () {
    $conn = vadaTenant();
    Storage::fake('vada');

    Storage::disk('vada')->put('vada/lama/foto.jpg', 'x');

    [, $messageId] = vadaMediaMessage($conn, [
        'media_type' => 'image',
        'media_path' => 'vada/lama/foto.jpg',
        'media_name' => 'foto.jpg',
        'media_status' => 'ready',
        'created_at' => now()->subDays(120),
    ]);

    $this->artisan('vada:prune-media', ['--tenant' => 'tenant-vada', '--days' => 100])
        ->assertSuccessful();

    $row = DB::connection($conn)->table('vada_messages')->where('id', $messageId)->first();

    expect($row)->not->toBeNull()
        ->and($row->media_status)->toBe('expired')
        ->and($row->media_path)->toBeNull()
        ->and(Storage::disk('vada')->exists('vada/lama/foto.jpg'))->toBeFalse();
});
