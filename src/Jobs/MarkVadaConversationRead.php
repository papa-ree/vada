<?php

namespace Bale\Vada\Jobs;

use Bale\Wara\Facades\Wara;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Mengirim tanda dibaca ke WhatsApp untuk pesan masuk yang baru dibuka admin.
 *
 * Satu percobaan saja: tanda dibaca bersifat kosmetik, dan GOWA tidak perlu
 * dipanggil berulang hanya karena pesan sudah tidak ada di chat storage.
 */
class MarkVadaConversationRead implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $deviceId,
        public string $waraMessageId,
        public string $phone,
    ) {
        $this->onConnection(config('vada.queue.connection') ?: null);
        $this->onQueue(config('vada.queue.name', 'default'));
    }

    public function handle(): void
    {
        try {
            Wara::markRead($this->deviceId, $this->waraMessageId, $this->phone);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
