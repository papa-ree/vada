<?php

namespace Bale\Vada\Commands;

use Illuminate\Console\Command;

class PublishMigrationsVada extends Command
{
    protected $signature = 'vada:publish-migrations {--force : Timpa file migrasi yang sudah dipublikasikan}';

    protected $description = 'Publikasikan berkas migrasi tenant bale/vada';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'vada:migrations',
            '--force' => (bool) $this->option('force'),
        ]);

        $count = count(glob(database_path('migrations/vada/*.php')) ?: []);

        $this->info("{$count} berkas migrasi dipublikasikan ke database/migrations/vada.");

        return self::SUCCESS;
    }
}
