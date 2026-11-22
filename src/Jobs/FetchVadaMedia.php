<?php

namespace Bale\Vada\Jobs;

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\Media;
use Bale\Wara\Facades\Wara;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Menarik media pesan masuk dari gateway dan menyimpannya di disk Bale.
 *
 * Dipisah dari listener karena unduhan bisa lambat; baris pesan sudah tampil
 * lebih dulu dengan status "mengunduh".
 */
class FetchVadaMedia implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(
        public string $baleId,
        public string $messageId,
        public string $deviceId,
        public string $phone,
        public string $waraMessageId,
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

            if ($message === null || $message->media_status !== VadaMessage::MEDIA_PENDING) {
                return;
            }

            try {
                $download = Wara::downloadMedia($this->deviceId, $this->waraMessageId, $this->phone);

                if ($download === null) {
                    throw new RuntimeException('Gateway tidak menyediakan media untuk pesan ini.');
                }

                // Ditolak sejak awal (bukan gagal sementara), jadi jangan diulang.
                if ($download->size > Media::maxBytes()
                    || ! Media::allows($message->media_type, $download->filename)) {
                    $message->forceFill(['media_status' => VadaMessage::MEDIA_FAILED])->save();

                    return;
                }

                $stored = Media::save($download->contents, $this->baleId, $message->id, $download->filename);

                $message->forceFill([
                    'media_path' => $stored['path'],
                    'media_name' => $stored['name'],
                    'media_mime' => $stored['mime'],
                    'media_size' => $stored['size'],
                    'media_status' => VadaMessage::MEDIA_READY,
                ])->save();
            } catch (Throwable $e) {
                if ($this->attempts() < $this->tries) {
                    throw $e;
                }

                report($e);

                $message->forceFill(['media_status' => VadaMessage::MEDIA_FAILED])->save();
            }
        } finally {
            TenantManager::clear();
        }
    }
}
