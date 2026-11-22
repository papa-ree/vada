<?php

namespace Bale\Vada\Commands;

use Bale\Cms\Models\BaleList;
use Bale\Cms\Services\TenantManager;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Hapus berkas media yang lebih tua dari batas retensi. Baris pesan tetap ada
 * dengan status `expired`, supaya riwayat percakapan tidak bolong.
 */
class PruneVadaMedia extends Command
{
    protected $signature = 'vada:prune-media
        {--days= : Umur media dalam hari}
        {--tenant= : Slug tenant (tanpa ini: semua tenant)}';

    protected $description = 'Hapus media chat Vada yang melewati batas retensi';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('vada.media.prune_days', 100));
        $cutoff = now()->subDays($days);

        $tenants = BaleList::query()
            ->when($this->option('tenant'), fn ($query, $slug) => $query->where('slug', $slug))
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('Tidak ada tenant yang cocok.');

            return self::FAILURE;
        }

        $pruned = 0;

        foreach ($tenants as $tenant) {
            try {
                TenantManager::initializeFromBaleUuid($tenant->id);

                $messages = VadaMessage::query()
                    ->whereNotNull('media_path')
                    ->where('created_at', '<', $cutoff)
                    ->get();

                foreach ($messages as $message) {
                    try {
                        Storage::disk(Media::disk())->delete($message->media_path);
                    } catch (Throwable $e) {
                        report($e);
                    }

                    $message->forceFill([
                        'media_path' => null,
                        'media_status' => VadaMessage::MEDIA_EXPIRED,
                    ])->save();

                    $pruned++;
                }
            } catch (Throwable $e) {
                $this->error("Tenant {$tenant->slug} gagal: {$e->getMessage()}");
            } finally {
                TenantManager::clear();
            }
        }

        $this->info("Media kedaluwarsa yang dihapus: {$pruned} (batas {$days} hari).");

        return self::SUCCESS;
    }
}
