<?php

use App\Models\User;
use Bale\Cms\Models\BaleList;
use Bale\Cms\Models\BaleUser;
use Bale\Cms\Services\TenantManager;
use Bale\Vada\Models\VadaChannel;
use Bale\Wara\Events\WaraIncomingMessage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

const VADA_TEST_BALE = '11111111-1111-1111-1111-111111111111';

/**
 * Siapkan database tenant + baris `bale_lists`.
 *
 * @return string nama koneksi tenant
 */
function vadaTenant(): string
{
    $name = 'bale_'.str_replace('-', '_', VADA_TEST_BALE);

    config(["database.connections.{$name}" => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]]);

    // Resolve sekarang: objek koneksi ini yang dipakai selamanya, walau config
    // koneksi kemudian ditimpa config mysql oleh TenantManager.
    DB::connection($name)->getPdo();

    Schema::connection($name)->create('vada_conversations', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->text('contact_phone');
        $table->char('contact_phone_hash', 64)->unique();
        $table->timestamp('last_message_at')->nullable();
        $table->unsignedInteger('unread_count')->default(0);
        $table->string('status')->default('open');
        $table->timestamps();
    });

    Schema::connection($name)->create('vada_messages', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('conversation_id');
        $table->string('direction');
        $table->uuid('reply_to_id')->nullable();
        $table->string('wara_message_id')->nullable();
        $table->text('body');
        $table->string('media_type')->nullable();
        $table->string('media_path')->nullable();
        $table->string('media_name')->nullable();
        $table->string('media_mime')->nullable();
        $table->unsignedBigInteger('media_size')->nullable();
        $table->string('media_status')->nullable();
        $table->string('status');
        $table->text('error')->nullable();
        $table->uuid('sent_by')->nullable();
        $table->timestamps();
    });

    // `id` ada di `$guarded`, jadi tidak bisa lewat create().
    $bale = new BaleList([
        'organization_id' => '22222222-2222-2222-2222-222222222222',
        'name' => 'Tenant Vada',
        'slug' => 'tenant-vada',
        'database_host' => '127.0.0.1',
        'database_name' => 'tenant_vada',
        'database_username' => 'u',
        'database_password' => 'p',
        'is_active' => true,
    ]);
    $bale->id = VADA_TEST_BALE;
    $bale->save();

    return $name;
}

function vadaIncoming(
    string $deviceId = 'dev-a',
    string $messageId = 'msg-1',
    string $phone = '628123456789',
    string $body = 'halo',
    string $chatId = '628123456789@s.whatsapp.net',
    ?array $media = null,
    bool $fromMe = false,
    ?string $repliedToId = null,
    ?string $quotedBody = null,
): WaraIncomingMessage {
    return new WaraIncomingMessage(
        $deviceId,
        'message',
        $messageId,
        $phone,
        $chatId,
        $body,
        '{}',
        now()->toIso8601String(),
        $media,
        $fromMe,
        $repliedToId,
        $quotedBody,
    );
}

/**
 * Siapkan sesi tenant + user yang boleh membuka chat.
 */
function vadaInboxSession(string $conn): User
{
    Permission::firstOrCreate(['name' => 'vada.access', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->givePermissionTo('vada.access');

    $baleUser = new BaleUser(['bale_id' => VADA_TEST_BALE, 'user_uuid' => $user->uuid]);
    $baleUser->save();

    session(['bale_active_uuid' => VADA_TEST_BALE]);

    // Sama seperti AgendaDecisionTest: koneksi sqlite yang sudah di-resolve
    // dipasang sebagai koneksi aktif, jadi `ensureActive()` tidak menimpa
    // config dengan MySQL.
    $prop = (new ReflectionClass(TenantManager::class))->getProperty('activeConnection');
    $prop->setAccessible(true);
    $prop->setValue(null, $conn);

    return $user;
}

function vadaChannel(string $deviceId = 'dev-a', bool $active = true): VadaChannel
{
    return VadaChannel::create([
        'bale_id' => VADA_TEST_BALE,
        'device_id' => $deviceId,
        'is_active' => $active,
    ]);
}
