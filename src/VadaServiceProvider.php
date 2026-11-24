<?php

namespace Bale\Vada;

use Bale\Vada\Commands\AssignVada;
use Bale\Vada\Commands\InstallVada;
use Bale\Vada\Commands\MigrateVada;
use Bale\Vada\Commands\PruneVadaMedia;
use Bale\Vada\Commands\PublishConfigVada;
use Bale\Vada\Commands\PublishMigrationsVada;
use Bale\Vada\Commands\RepairVadaEchoes;
use Bale\Vada\Commands\SyncVadaHistory;
use Bale\Vada\Commands\UnassignVada;
use Bale\Vada\Listeners\StoreIncomingVadaMessage;
use Bale\Vada\Listeners\StoreOutgoingVadaMessage;
use Bale\Vada\Listeners\UpdateVadaMessageStatus;
use Bale\Vada\Services\ContextRegistry;
use Bale\Wara\Events\WaraIncomingMessage;
use Bale\Wara\Events\WaraMessageAck;
use Bale\Wara\Events\WaraMessageSent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

class VadaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vada.php', 'vada');

        $this->app->singleton(ContextRegistry::class);

        $this->registerMediaDisk();

        $this->commands([
            AssignVada::class,
            InstallVada::class,
            MigrateVada::class,
            PruneVadaMedia::class,
            PublishConfigVada::class,
            PublishMigrationsVada::class,
            RepairVadaEchoes::class,
            SyncVadaHistory::class,
            UnassignVada::class,
        ]);
    }

    /**
     * Disk media Vada belum tentu ada di config/filesystems aplikasi.
     *
     * Produksi memakai bucket MinIO terpisah (objek privat) dengan endpoint dan
     * kredensial yang sama seperti disk `s3`; lokal memakai direktori privat.
     */
    protected function registerMediaDisk(): void
    {
        $disk = config('vada.media.disk', 'vada');

        if (config("filesystems.disks.{$disk}")) {
            return;
        }

        config(["filesystems.disks.{$disk}" => app()->isProduction() ? [
            'driver' => 's3',
            'key' => config('filesystems.disks.s3.key'),
            'secret' => config('filesystems.disks.s3.secret'),
            'region' => config('filesystems.disks.s3.region'),
            'bucket' => config('vada.media.bucket'),
            'endpoint' => config('filesystems.disks.s3.endpoint'),
            'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint'),
            'visibility' => 'private',
            'throw' => false,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/'.$disk),
            'throw' => false,
        ]]);
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vada');

        // Hanya tabel landlord yang dimuat otomatis; stub tenant dipublikasikan
        // dan dijalankan per database tenant lewat `vada:migrate`.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/landlord');

        $this->offerPublishing();
        $this->registerLivewireComponents();

        Event::listen(WaraIncomingMessage::class, StoreIncomingVadaMessage::class);
        Event::listen(WaraMessageSent::class, StoreOutgoingVadaMessage::class);
        Event::listen(WaraMessageAck::class, UpdateVadaMessageStatus::class);

        $this->scheduleMediaPrune();
    }

    /**
     * Retensi media dijalankan scheduler, bukan worker antrean.
     */
    protected function scheduleMediaPrune(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('vada:prune-media')
                ->daily()
                ->withoutOverlapping();
        });
    }

    protected function offerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/vada.php' => config_path('vada.php'),
        ], 'vada:config');

        $this->publishes($this->getTenantMigrations(), 'vada:migrations');
    }

    /**
     * @return array<string, string>
     */
    protected function getTenantMigrations(): array
    {
        $migrations = [];

        foreach (glob(__DIR__.'/../database/migrations/tenant/*.php.stub') ?: [] as $file) {
            $migrations[$file] = database_path(
                'migrations/vada/'.str_replace('.php.stub', '.php', basename($file))
            );
        }

        return $migrations;
    }

    /**
     * Alias Livewire `vada.` mengikuti struktur folder kebab-case:
     * src/Livewire/SharedComponents/Chat/Inbox.php -> vada.shared-components.chat.inbox
     */
    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder;
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relative = $file->getRelativePathname();
            $class = 'Bale\\Vada\\Livewire\\'.Str::beforeLast(
                str_replace(['/', '\\'], '\\', $relative),
                '.php'
            );

            if (! class_exists($class) || ! is_subclass_of($class, LivewireComponent::class)) {
                continue;
            }

            $segments = preg_split('#[\\/\\\\]#', Str::replaceLast('.php', '', $relative));
            $kebab = array_map(fn ($s) => Str::kebab($s), $segments);

            Livewire::component('vada.'.implode('.', $kebab), $class);
        }
    }
}
