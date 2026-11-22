<?php

namespace Bale\Vada\Commands;

use Illuminate\Console\Command;

class PublishConfigVada extends Command
{
    protected $signature = 'vada:publish-config {--force : Timpa config yang sudah ada}';

    protected $description = 'Publikasikan config bale/vada';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'vada:config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->info('Config Vada dipublikasikan ke config/vada.php.');

        return self::SUCCESS;
    }
}
