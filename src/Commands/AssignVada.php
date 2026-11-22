<?php

namespace Bale\Vada\Commands;

use Bale\Cms\Models\BaleList;
use Bale\Vada\Models\VadaChannel;
use Bale\Wara\Models\WaraSession;
use Illuminate\Console\Command;

class AssignVada extends Command
{
    protected $signature = 'vada:assign
        {tenant? : Slug atau UUID tenant. Kosong = pilih dari daftar}
        {device? : Session ID device di wara_sessions. Kosong = pilih dari daftar}
        {--label= : Nama kanal untuk ditampilkan. Kosong = ditanyakan saat interaktif}
        {--disable : Nonaktifkan kanal tenant ini}';

    protected $description = 'Tetapkan atau nonaktifkan nomor WhatsApp Vada untuk satu tenant';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        if ($this->option('disable')) {
            $channel = VadaChannel::where('bale_id', $tenant->id)->first();

            if ($channel === null) {
                $this->error("Tenant {$tenant->slug} belum punya kanal Vada.");

                return self::FAILURE;
            }

            $channel->forceFill(['is_active' => false])->save();

            $this->info("Kanal Vada untuk {$tenant->slug} dinonaktifkan.");

            return self::SUCCESS;
        }

        $deviceId = $this->resolveDevice();

        if ($deviceId === null) {
            return self::FAILURE;
        }

        if (! WaraSession::where('device_id', $deviceId)->exists()) {
            $this->error("Device '{$deviceId}' tidak ada di wara_sessions. Jalankan wara:sync-devices lebih dulu.");

            return self::FAILURE;
        }

        $taken = VadaChannel::where('device_id', $deviceId)
            ->where('bale_id', '!=', $tenant->id)
            ->first();

        if ($taken !== null) {
            $this->error("Device '{$deviceId}' sudah dipakai tenant lain. Satu nomor hanya untuk satu tenant.");

            return self::FAILURE;
        }

        $channel = VadaChannel::updateOrCreate(
            ['bale_id' => $tenant->id],
            [
                'device_id' => $deviceId,
                'label' => $this->resolveLabel(),
                'is_active' => true,
            ],
        );

        $this->info("Kanal Vada {$tenant->slug} memakai device {$channel->device_id}.");

        return self::SUCCESS;
    }

    protected function resolveTenant(): ?BaleList
    {
        $argument = $this->argument('tenant');

        if (filled($argument)) {
            $tenant = BaleList::where('slug', $argument)->orWhere('id', $argument)->first();

            if ($tenant === null) {
                $this->error('Tenant tidak ditemukan.');
            }

            return $tenant;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Slug tenant wajib diisi saat dijalankan non-interaktif.');

            return null;
        }

        $tenants = BaleList::orderBy('name')->get();

        if ($tenants->isEmpty()) {
            $this->error('Belum ada tenant di bale_lists.');

            return null;
        }

        $labels = $tenants
            ->map(fn (BaleList $tenant) => $tenant->name.' ('.$tenant->slug.')'.($tenant->is_active ? '' : ' — nonaktif'))
            ->values()
            ->all();

        $index = $this->pickIndex('Pilih tenant', $labels);

        if ($index === null) {
            $this->error('Pilihan tenant tidak valid.');

            return null;
        }

        return $tenants->values()[$index];
    }

    protected function resolveDevice(): ?string
    {
        $argument = $this->argument('device');

        if (filled($argument)) {
            return (string) $argument;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Device wajib diisi kecuali memakai --disable.');

            return null;
        }

        $sessions = WaraSession::orderBy('display_name')->get();

        if ($sessions->isEmpty()) {
            $this->error('Belum ada device di wara_sessions. Jalankan wara:sync-devices lebih dulu.');

            return null;
        }

        $labels = $sessions
            ->map(fn (WaraSession $session) => $session->device_id
                .' — '.($session->display_name ?: $session->jid ?: 'tanpa nama')
                .' ('.$session->state_label.')')
            ->values()
            ->all();

        $index = $this->pickIndex('Pilih device WhatsApp', $labels);

        if ($index === null) {
            $this->error('Pilihan device tidak valid.');

            return null;
        }

        return (string) $sessions->values()[$index]->device_id;
    }

    /**
     * Daftar bernomor seperti `vada:install`: `choice()` mengembalikan labelnya,
     * dan indeks angka saat default yang dipakai.
     *
     * @param  array<int, string>  $labels
     */
    protected function pickIndex(string $question, array $labels): ?int
    {
        $picked = $this->choice($question, $labels, 0);

        if (is_numeric($picked) && isset($labels[(int) $picked])) {
            return (int) $picked;
        }

        $index = array_search($picked, $labels, true);

        return $index === false ? null : $index;
    }

    protected function resolveLabel(): ?string
    {
        $label = $this->option('label');

        if ($label !== null || ! $this->input->isInteractive()) {
            return $label;
        }

        return $this->ask('Label kanal (kosongkan untuk tanpa label)');
    }
}
