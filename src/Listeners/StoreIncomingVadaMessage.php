<?php

namespace Bale\Vada\Listeners;

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Jobs\FetchVadaMedia;
use Bale\Vada\Models\VadaChannel;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\PhoneIndex;
use Bale\Wara\Events\WaraIncomingMessage;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menulis pesan masuk ke database tenant pemilik device.
 *
 * Sinkron, bukan queued: satu insert, dan `wara_logs` sudah menjadi bukti bila
 * penyimpanan tenant gagal. Kegagalan tidak boleh menggagalkan webhook.
 */
class StoreIncomingVadaMessage
{
    public function handle(WaraIncomingMessage $event): void
    {
        $outgoing = $event->isFromMe();
        $hasText = $event->body !== null && trim($event->body) !== '';

        // Echo pesan yang dikirim dari HP hanya melengkapi riwayat teks. Media
        // milik sendiri tidak perlu diunduh dari gateway.
        if ($outgoing && ! $hasText) {
            return;
        }

        // Lawan bicara datang dari `chat_id`, bukan `from`: pesan yang dikirim
        // dari HP punya `from` = nomor sendiri. Lihat WaraIncomingMessage::chatPhone().
        $chatPhone = $event->chatPhone();

        if ($chatPhone === null || (! $outgoing && ! $event->isAddressableOneToOne())) {
            return;
        }

        $channel = VadaChannel::query()
            ->where('device_id', $event->deviceId)
            ->where('is_active', true)
            ->first();

        if ($channel === null || ! $channel->bale_id) {
            return;
        }

        $phone = PhoneIndex::normalize($chatPhone);

        if ($phone === null) {
            return;
        }

        try {
            TenantManager::initializeFromBaleUuid($channel->bale_id);

            $pending = null;

            DB::connection(TenantManager::getActiveConnection())->transaction(function () use ($event, $phone, $outgoing, &$pending) {
                $conversation = VadaConversation::forPhone($phone);

                if ($event->messageId !== null && VadaMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('wara_message_id', $event->messageId)
                    ->exists()) {
                    return;
                }

                // shortcut: tanpa message_id dari gateway tidak ada kunci idempotensi,
                // upgrade ke rekonsiliasi wara_logs bila GOWA mulai mengirim id kosong.

                // Kutipan hanya ditautkan bila pesan yang dikutip tersimpan lokal;
                // selain itu pesan tetap masuk tanpa blok kutipan.
                $replyToId = $event->repliedToId === null ? null : VadaMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('wara_message_id', $event->repliedToId)
                    ->value('id');

                $type = $outgoing ? null : $event->mediaType();

                $message = VadaMessage::create([
                    'conversation_id' => $conversation->id,
                    'direction' => $outgoing ? VadaMessage::DIRECTION_OUT : VadaMessage::DIRECTION_IN,
                    'reply_to_id' => $replyToId,
                    'wara_message_id' => $event->messageId,
                    'body' => (string) $event->body,
                    'status' => $outgoing ? VadaMessage::STATUS_SENT : VadaMessage::STATUS_RECEIVED,
                    'media_type' => $type,
                    'media_name' => $outgoing ? null : ($event->media['filename'] ?? null),
                    'media_status' => $type === null ? null : match (true) {
                        // Video & stiker tetap tampil sebagai baris, bukan hilang.
                        ! in_array($type, ['image', 'audio', 'document'], true) => VadaMessage::MEDIA_FAILED,
                        $event->messageId === null => VadaMessage::MEDIA_FAILED,
                        default => VadaMessage::MEDIA_PENDING,
                    },
                ]);

                $conversation->forceFill([
                    'last_message_at' => now(),
                    'unread_count' => $outgoing ? (int) $conversation->unread_count : $conversation->unread_count + 1,
                ])->save();

                if ($message->media_status === VadaMessage::MEDIA_PENDING) {
                    $pending = $message;
                }
            });

            if ($pending !== null) {
                FetchVadaMedia::dispatch(
                    (string) $channel->bale_id,
                    $pending->id,
                    $event->deviceId,
                    (string) $event->phone,
                    (string) $event->messageId,
                );
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            TenantManager::clear();
        }
    }
}
