<?php

namespace Bale\Vada\Listeners;

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Models\VadaChannel;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\PhoneIndex;
use Bale\Wara\Events\WaraMessageAck;
use Throwable;

/**
 * Memetakan bukti terkirim/dibaca dari gateway ke status pesan keluar.
 *
 * Urutan status hanya boleh naik: ack `delivered` yang datang setelah `read`
 * adalah keterlambatan jaringan, bukan pembatalan.
 */
class UpdateVadaMessageStatus
{
    /** Bobot urutan status; yang lebih kecil tidak boleh menimpa yang lebih besar. */
    protected const ORDER = [
        VadaMessage::STATUS_QUEUED => 0,
        VadaMessage::STATUS_SENT => 1,
        VadaMessage::STATUS_DELIVERED => 2,
        VadaMessage::STATUS_READ => 3,
        // Tombstone tidak boleh dinaikkan lagi oleh ack yang datang telat.
        VadaMessage::STATUS_DELETED => 99,
    ];

    public function handle(WaraMessageAck $event): void
    {
        $baleId = VadaChannel::query()
            ->where('device_id', $event->deviceId)
            ->where('is_active', true)
            ->value('bale_id');

        if ($baleId === null) {
            return;
        }

        try {
            TenantManager::initializeFromBaleUuid($baleId);

            if ($event->receiptType === 'read-self') {
                $this->clearUnread($event->phone);

                return;
            }

            $status = match ($event->receiptType) {
                'delivered' => VadaMessage::STATUS_DELIVERED,
                'read', 'played' => VadaMessage::STATUS_READ,
                default => null,
            };

            if ($status === null || $event->messageIds === []) {
                return;
            }

            $rank = self::ORDER[$status];

            VadaMessage::query()
                ->where('direction', VadaMessage::DIRECTION_OUT)
                ->whereIn('wara_message_id', $event->messageIds)
                ->get()
                ->each(function (VadaMessage $message) use ($status, $rank) {
                    if ($rank > (self::ORDER[$message->status] ?? -1)) {
                        $message->forceFill(['status' => $status])->save();
                    }
                });
        } catch (Throwable $e) {
            report($e);
        } finally {
            TenantManager::clear();
        }
    }

    /**
     * Akun ini membaca pesan di perangkat lain; badge Vada ikut hilang.
     */
    protected function clearUnread(?string $phone): void
    {
        if ($phone === null || $phone === '') {
            return;
        }

        VadaConversation::query()
            ->where('contact_phone_hash', PhoneIndex::make($phone))
            ->where('unread_count', '>', 0)
            ->update(['unread_count' => 0]);
    }
}
