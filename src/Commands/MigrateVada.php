<?php

namespace Bale\Vada\Commands;

use Bale\Cms\Models\BaleList;
use Bale\Cms\Services\TenantManager;
use Illuminate\Console\Command;
use Throwable;

class MigrateVada extends Command
{
    protected $signature = 'vada:migrate {--tenant= : Slug tenant yang akan dimigrasi}';

    protected $description = 'Jalankan migrasi tabel Vada untuk database tenant tertentu';

    public function handle(): int
    {
        $path = 'database/migrations/vada';
        $published = glob(base_path($path.'/*.php')) ?: [];

        if ($published === []) {
            $this->error('Migrasi belum dipublikasikan. Jalankan dulu: php artisan vada:publish-migrations');

            return self::FAILURE;
        }

        $tenantSlug = $this->option('tenant');

        if (! $tenantSlug) {
            $tenants = BaleList::all();

            if ($tenants->isEmpty()) {
                $this->error('Tidak ada tenant pada tabel bale_lists.');

                return self::FAILURE;
            }

            $tenantSlug = $this->choice(
                'Database tenant mana yang akan dimigrasi?',
                $tenants->pluck('slug')->toArray()
            );
        }

        try {
            $tenant = BaleList::where('slug', $tenantSlug)->firstOrFail();

            TenantManager::initializeFromBaleUuid($tenant->id);
            $connection = TenantManager::getActiveConnection();

            if (! $connection) {
                throw new \Exception("Gagal mengaktifkan koneksi untuk tenant {$tenant->slug}");
            }

            $this->info("Menjalankan migrasi tabel Vada (DB: {$tenant->database_name})...");

            $this->call('migrate', [
                '--database' => $connection,
                '--path' => $path,
                '--force' => true,
            ]);

            $this->info("Migrasi untuk tenant {$tenant->slug} selesai.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Migrasi gagal: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            TenantManager::clear();
        }
    }
}
