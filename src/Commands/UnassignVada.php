<?php

namespace Bale\Vada\Commands;

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Commands\Concerns\ResolvesVadaTenant;
use Bale\Vada\Models\VadaChannel;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Melepas nomor WhatsApp tenant sekaligus menghapus riwayat chat Vada miliknya.
 * Tabel Wara/Bale tidak disentuh: riwayat gateway tetap utuh.
 */
class UnassignVada extends Command
{
    use ResolvesVadaTenant;

    protected $signature = 'vada:unassign
        {tenant? : Slug atau UUID tenant. Kosong = pilih dari daftar}';

    protected $description = 'Lepas nomor WhatsApp Vada dari tenant dan hapus riwayat chat Vada-nya';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Perintah ini menghapus riwayat chat; jalankan tanpa --no-interaction.');

            return self::FAILURE;
        }

        $typed = $this->ask("Ketik slug tenant ({$tenant->slug}) untuk melanjutkan");

        if (trim((string) $typed) !== $tenant->slug) {
            $this->error('Dibatalkan: slug tidak cocok.');

            return self::FAILURE;
        }

        try {
            TenantManager::initializeFromBaleUuid($tenant->id);

            $counts = $this->purge();
        } catch (Throwable $e) {
            $this->error("Gagal membersihkan data {$tenant->slug}: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            TenantManager::clear();
        }

        $released = VadaChannel::where('bale_id', $tenant->id)->delete() > 0;

        $this->info("{$tenant->slug}: {$counts['conversations']} percakapan, {$counts['messages']} pesan, {$counts['media']} berkas media dihapus.");

        if ($released) {
            $this->info('Kanal Vada dilepas.');
        } else {
            $this->warn("Tenant {$tenant->slug} tidak punya kanal Vada.");
        }

        return self::SUCCESS;
    }

    /**
     * Chunk agar tenant besar tidak menghabiskan memori, dan agar media
     * dihapus per batch (satu request per 1000 objek ke MinIO).
     *
     * @return array{conversations: int, messages: int, media: int}
     */
    protected function purge(): array
    {
        $disk = Storage::disk(Media::disk());
        $media = 0;

        VadaMessage::query()
            ->whereNotNull('media_path')
            ->orderBy('id')
            ->chunk(1000, function ($messages) use ($disk, &$media) {
                $paths = $messages->pluck('media_path')->all();

                try {
                    $disk->delete($paths);
                    $media += count($paths);
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $counts = [
            'conversations' => VadaConversation::query()->count(),
            'messages' => VadaMessage::query()->count(),
            'media' => $media,
        ];

        // FK cascade bisa belum ada di database tenant lama, jadi hapus eksplisit.
        VadaMessage::query()->delete();
        VadaConversation::query()->delete();

        return $counts;
    }
}
