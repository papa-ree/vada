<?php

use App\Models\User;
use Bale\Cms\Models\BaleUser;
use Bale\Cms\Services\TenantManager;
use Bale\Vada\Livewire\SharedComponents\Chat\Inbox;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\PhoneIndex;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support.php';

beforeEach(function () {
    TenantManager::clear();
    Cache::flush();
    Storage::fake('vada');
    config([
        'vada.index_key' => 'kunci-index-vada-test',
        'wara.endpoint' => 'http://gateway.test',
    ]);

    Http::preventStrayRequests();
    Http::fake(['gateway.test/*' => Http::response(['code' => 'SUCCESS'], 200)]);
});

afterEach(function () {
    Auth::logout();
    TenantManager::clear();
});

/**
 * Sesi tenant + user yang boleh membuka chat.
 */
function vadaDeleteSession(string $conn): User
{
    Permission::firstOrCreate(['name' => 'vada.access', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->givePermissionTo('vada.access');

    $baleUser = new BaleUser(['bale_id' => VADA_TEST_BALE, 'user_uuid' => $user->uuid]);
    $baleUser->save();

    session(['bale_active_uuid' => VADA_TEST_BALE]);

    $prop = (new ReflectionClass(TenantManager::class))->getProperty('activeConnection');
    $prop->setAccessible(true);
    $prop->setValue(null, $conn);

    return $user;
}

function vadaDeleteConversation(string $phone = '628123456789'): VadaConversation
{
    return VadaConversation::create([
        'contact_phone' => $phone,
        'contact_phone_hash' => PhoneIndex::make($phone),
        'last_message_at' => now(),
        'unread_count' => 0,
        'status' => 'open',
    ]);
}

/** @param array<string, mixed> $attributes */
function vadaDeleteMessage(VadaConversation $conversation, array $attributes = []): VadaMessage
{
    return VadaMessage::create(array_merge([
        'conversation_id' => $conversation->id,
        'direction' => 'out',
        'wara_message_id' => 'msg-1',
        'body' => 'halo',
        'status' => VadaMessage::STATUS_DELIVERED,
    ], $attributes));
}

it('menghapus pesan untuk saya beserta berkasnya', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaDeleteSession($conn);
    $conversation = vadaDeleteConversation();

    Storage::disk('vada')->put('vada/foto.jpg', 'x');

    $message = vadaDeleteMessage($conversation, [
        'media_type' => 'image',
        'media_path' => 'vada/foto.jpg',
        'media_name' => 'foto.jpg',
        'media_mime' => 'image/jpeg',
        'media_size' => 1,
        'media_status' => VadaMessage::MEDIA_READY,
    ]);

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id)
        ->call('deleteMessageForMe', $message->id)
        ->assertDispatched('toast');

    expect(VadaMessage::find($message->id))->toBeNull()
        ->and(Storage::disk('vada')->exists('vada/foto.jpg'))->toBeFalse();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/message/msg-1/delete'));
});

it('menarik pesan untuk semua lalu menyisakan tombstone tanpa isi', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaDeleteSession($conn);
    $conversation = vadaDeleteConversation();

    Storage::disk('vada')->put('vada/foto.jpg', 'x');

    $message = vadaDeleteMessage($conversation, [
        'media_type' => 'image',
        'media_path' => 'vada/foto.jpg',
        'media_name' => 'foto.jpg',
        'media_mime' => 'image/jpeg',
        'media_size' => 1,
        'media_status' => VadaMessage::MEDIA_READY,
    ]);

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id)
        ->call('deleteMessageForEveryone', $message->id)
        ->assertDispatched('toast');

    $fresh = $message->fresh();

    expect($fresh->status)->toBe(VadaMessage::STATUS_DELETED)
        ->and($fresh->body)->toBe('')
        ->and($fresh->media_type)->toBeNull()
        ->and($fresh->media_path)->toBeNull()
        ->and(Storage::disk('vada')->exists('vada/foto.jpg'))->toBeFalse();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/message/msg-1/revoke'));
});

it('menolak tarik pesan masuk dan pesan dari percakapan lain', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaDeleteSession($conn);
    $conversation = vadaDeleteConversation();
    $other = vadaDeleteConversation('628987654321');

    $incoming = vadaDeleteMessage($conversation, [
        'direction' => 'in',
        'wara_message_id' => 'in-1',
        'status' => VadaMessage::STATUS_RECEIVED,
    ]);
    $foreign = vadaDeleteMessage($other);

    $component = Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id);

    $component->call('deleteMessageForEveryone', $incoming->id);
    $component->call('deleteMessageForEveryone', $foreign->id);

    expect($incoming->fresh()->status)->toBe(VadaMessage::STATUS_RECEIVED)
        ->and($foreign->fresh())->not->toBeNull();

    Http::assertNothingSent();
});

it('menampilkan menu hapus dan tombstone setelah ditarik', function () {
    $conn = vadaTenant();
    vadaChannel();
    $user = vadaDeleteSession($conn);
    $conversation = vadaDeleteConversation();
    $message = vadaDeleteMessage($conversation);

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->set('conversationId', $conversation->id)
        ->assertSee(__('Hapus untuk saya'))
        ->call('deleteMessageForEveryone', $message->id)
        ->assertSee(__('Pesan ini telah dihapus.'));
});
