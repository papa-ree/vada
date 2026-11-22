<?php

namespace Bale\Vada\Jobs;

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\Media;
use Bale\Wara\Exceptions\CooldownException;
use Bale\Wara\Facades\Wara;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Mengirim balasan admin. Hanya menerima scalar: model tenant yang
 * diserialisasi akan dipulihkan lewat koneksi default (landlord).
 */
class SendVadaMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(
        public string $baleId,
        public string $messageId,
        public string $deviceId,
        public string $phone,
        public string $body,
        public ?string $mediaType = null,
    ) {
        $this->tries = max(1, (int) config('vada.queue.tries', 3));
        $this->onConnection(config('vada.queue.connection') ?: null);
        $this->onQueue(config('vada.queue.name', 'default'));
    }

    public function handle(): void
    {
        try {
            TenantManager::initializeFromBaleUuid($this->baleId);

            $message = VadaMessage::find($this->messageId);

            if ($message === null || $message->status !== VadaMessage::STATUS_QUEUED) {
                return;
            }

            try {
                $mediaUrl = $this->mediaType !== null
                    ? Media::signedUrl($message, $this->baleId)
                    : null;

                if ($this->mediaType !== null && $mediaUrl === null) {
                    // Tidak ada yang bisa diulang: berkasnya memang tidak ada
                    // (mis. sudah dipurge). GOWA tidak boleh dipanggil.
                    $message->forceFill([
                        'status' => VadaMessage::STATUS_FAILED,
                        'error' => __('Berkas media tidak ditemukan.'),
                    ])->save();

                    return;
                }

                $result = $this->mediaType !== null
                    ? Wara::sendMedia(
                        $this->mediaType,
                        $this->phone,
                        (string) $mediaUrl,
                        $this->body,
                        deviceId: $this->deviceId,
                        notification: self::class,
                        replyToMessageId: $message->replyTo?->wara_message_id,
                    )
                    : Wara::sendMessage(
                        $this->phone,
                        $this->body,
                        deviceId: $this->deviceId,
                        notification: self::class,
                        replyToMessageId: $message->replyTo?->wara_message_id,
                    );

                $message->forceFill([
                    'status' => VadaMessage::STATUS_SENT,
                    'wara_message_id' => $result->messageId,
                    'error' => null,
                ])->save();
            } catch (CooldownException $e) {
                // Backoff device juga lewat sini (BackoffException). `release()`
                // tetap memakan jatah tries, jadi tanpa cek ini pesan akan
                // menggantung `queued` selamanya saat tries habis.
                if ($this->attempts() < $this->tries) {
                    $this->release($e->retryAfter);

                    return;
                }

                $message->forceFill([
                    'status' => VadaMessage::STATUS_FAILED,
                    'error' => $e->getMessage(),
                ])->save();
            } catch (Throwable $e) {
                if ($this->attempts() < $this->tries) {
                    throw $e;
                }

                $message->forceFill([
                    'status' => VadaMessage::STATUS_FAILED,
                    'error' => $e->getMessage(),
                ])->save();
            }
        } finally {
            TenantManager::clear();
        }
    }
}
