<?php

namespace Bale\Vada\Commands;

use Bale\Vada\VadaPermissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class InstallVada extends Command
{
    protected $signature = 'vada:install {--tenant= : Slug tenant untuk langkah migration} {--all : Jalankan semua langkah tanpa prompt}';

    protected $description = 'Installer bale/vada (config, permission, migration)';

    public function handle(): int
    {
        $option = $this->option('all') ? 0 : $this->choice(
            'Apa yang ingin dijalankan?',
            [
                0 => 'Semua (Config, Migrasi & Permission)',
                1 => 'Publikasikan config saja',
                2 => 'Role & permission saja',
                3 => 'Migrasi saja',
            ],
            0
        );

        $all = in_array($option, ['Semua (Config, Migrasi & Permission)', 0], true);

        try {
            if ($all || in_array($option, ['Publikasikan config saja', 1], true)) {
                $this->call('vada:publish-config', ['--force' => true]);
            }

            if ($all || in_array($option, ['Role & permission saja', 2], true)) {
                $this->seedPermissions();
            }

            if ($all || in_array($option, ['Migrasi saja', 3], true)) {
                if ($this->call('vada:publish-migrations', ['--force' => true]) !== self::SUCCESS) {
                    return self::FAILURE;
                }

                // Tabel landlord (vada_channels) ikut dimigrasi lewat jalur biasa.
                if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                    return self::FAILURE;
                }

                if ($this->call('vada:migrate', ['--tenant' => $this->option('tenant')]) !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }

            $this->info('Langkah instalasi Vada selesai.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Instalasi gagal: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function seedPermissions(): void
    {
        foreach (VadaPermissions::ALL as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission],
                ['guard_name' => 'web']
            );
        }

        $rootRole = Role::where('name', 'root')->first();

        if ($rootRole) {
            $rootRole->givePermissionTo(VadaPermissions::ALL);
        }

        Role::firstOrCreate(['name' => 'bale-vada-admin'], ['guard_name' => 'web'])
            ->syncPermissions(VadaPermissions::ALL);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Permission Vada berhasil dibuat.');
    }
}
