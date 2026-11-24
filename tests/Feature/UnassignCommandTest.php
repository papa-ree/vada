<?php

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Models\VadaChannel;
use Bale\Wara\Models\WaraSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support.php';

beforeEach(function () {
    TenantManager::clear();
});

/**
 * Percakapan + satu pesan di database tenant, dengan kolom media opsional.
 *
 * @param  array<string, mixed>  $extra
 */
function unassignMessage(string $conn, array $extra = []): string
{
    $conversationId = (string) Str::uuid();

    DB::connection($conn)->table('vada_conversations')->insert([
        'id' => $conversationId,
        'contact_phone' => '628123456789',
        'contact_phone_hash' => str_repeat('d', 64),
        'unread_count' => 0,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection($conn)->table('vada_messages')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'direction' => 'in',
        'wara_message_id' => 'msg-1',
        'body' => 'halo',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));

    return $conversationId;
}

it('menghapus riwayat chat, media, dan kanal tenant', function () {
    $conn = vadaTenant();
    vadaChannel();
    Storage::fake('vada');
    Storage::disk('vada')->put('vada/x/foto.webp', 'abc');

    unassignMessage($conn, [
        'media_type' => 'image',
        'media_path' => 'vada/x/foto.webp',
        'media_name' => 'foto.webp',
        'media_mime' => 'image/webp',
        'media_size' => 3,
        'media_status' => 'ready',
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

    $this->artisan('vada:unassign')
        ->expectsChoice('Pilih tenant', 'Tenant Vada (tenant-vada)', ['Tenant Vada (tenant-vada)'])
        ->expectsQuestion('Ketik slug tenant (tenant-vada) untuk melanjutkan', 'tenant-vada')
        ->assertSuccessful();

    expect(DB::connection($conn)->table('vada_conversations')->count())->toBe(0)
        ->and(DB::connection($conn)->table('vada_messages')->count())->toBe(0)
        ->and(VadaChannel::where('bale_id', VADA_TEST_BALE)->exists())->toBeFalse()
        ->and(Storage::disk('vada')->exists('vada/x/foto.webp'))->toBeFalse()
        ->and(WaraSession::where('device_id', 'dev-a')->exists())->toBeTrue();
});

it('membatalkan saat slug konfirmasi tidak cocok', function () {
    $conn = vadaTenant();
    vadaChannel();
    unassignMessage($conn);

    $this->artisan('vada:unassign', ['tenant' => 'tenant-vada'])
        ->expectsQuestion('Ketik slug tenant (tenant-vada) untuk melanjutkan', 'tenant-lain')
        ->assertFailed();

    expect(DB::connection($conn)->table('vada_messages')->count())->toBe(1)
        ->and(VadaChannel::where('bale_id', VADA_TEST_BALE)->exists())->toBeTrue();
});

it('menolak jalan non-interaktif', function () {
    vadaTenant();

    $this->artisan('vada:unassign', ['tenant' => 'tenant-vada', '--no-interaction' => true])
        ->expectsOutputToContain('jalankan tanpa --no-interaction')
        ->assertFailed();
});
