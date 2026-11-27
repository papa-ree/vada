<?php

namespace Bale\Vada\Listeners;

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Jobs\SendVadaMessage;
use Bale\Vada\Models\VadaChannel;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\PhoneIndex;
use Bale\Wara\Events\WaraMessageSent;
use Bale\Wara\Models\WaraLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mencatat pesan keluar yang dikirim package lain (notifikasi Sidulur,
 * Srikandi, dst.) lewat device milik kanal Vada, supaya ikut terlihat di
 * riwayat chat tenant.
 *
 * `WaraMessageSent` sengaja tidak membawa isi pesan, jadi isinya dibaca dari
 * `wara_logs` — baris itu ditulis `WaraManager::recordOutgoing()` tepat sebelum
 * event dipancarkan.
 */
class StoreOutgoingVadaMessage
{
    public function handle(WaraMessageSent $event): void
    {
        // Balasan admin ditulis SendVadaMessage sendiri; jangan digandakan.
        if ($event->notification === SendVadaMessage::class) {
            return;
        }

        $channel = VadaChannel::query()
            ->where('device_id', $event->deviceId)
            ->where('is_active', true)
            ->first();

        if ($channel === null || ! $channel->bale_id) {
            return;
        }

        $body = trim((string) ($event->messageId === null ? null : WaraLog::query()
            ->where('direction', 'out')
            ->where('message_id', $event->messageId)
            ->value('body')));

        $phone = PhoneIndex::normalize($event->destination);

        if ($body === '' || $phone === null) {
            return;
        }

        // Listener ini bisa berjalan di tengah request tenant lain, jadi
        // koneksi sebelumnya harus dipulihkan — `clear()` akan merusak request
        // yang sedang berjalan.
        $previous = TenantManager::getActiveConnection();
        $switched = $previous !== TenantManager::connectionName($channel->bale_id);

        try {
            if ($switched) {
                TenantManager::initializeFromBaleUuid($channel->bale_id);
            }

            DB::connection(TenantManager::getActiveConnection())->transaction(function () use ($event, $phone, $body) {
                $conversation = VadaConversation::forPhone($phone);

                if ($event->messageId !== null && VadaMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('wara_message_id', $event->messageId)
                    ->exists()) {
                    return;
                }

                // Event yang dipancarkan ulang tidak boleh menaikkan last_message_at
                // dua kali atau memicu duplikasi saat dua request bertabrakan.
                $message = VadaMessage::query()->createOrFirst(
                    [
                        'conversation_id' => $conversation->id,
                        'wara_message_id' => $event->messageId,
                    ],
                    [
                        'direction' => VadaMessage::DIRECTION_OUT,
                        'body' => $body,
                        'status' => VadaMessage::STATUS_SENT,
                    ],
                );

                if (! $message->wasRecentlyCreated) {
                    return;
                }

                $conversation->forceFill([
                    'last_message_at' => now(),
                    'unread_count' => (int) $conversation->unread_count,
                ])->save();
            });
        } catch (Throwable $e) {
            report($e);
        } finally {
            if ($switched) {
                $previousBaleId = $previous === null
                    ? null
                    : str_replace('_', '-', substr($previous, strlen('bale_')));

                $previousBaleId !== null && Str::isUuid($previousBaleId)
                    ? TenantManager::initializeFromBaleUuid($previousBaleId)
                    : TenantManager::clear();
            }
        }
    }
}
