<?php

namespace Bale\Vada\Commands;

use Bale\Cms\Models\BaleList;
use Bale\Vada\Models\VadaChannel;
use Bale\Wara\Models\WaraSession;
use Illuminate\Console\Command;

class AssignVada extends Command
{
    protected $signature = 'vada:assign
        {tenant : Slug atau UUID tenant}
        {device? : Session ID device di wara_sessions}
        {--label= : Nama kanal untuk ditampilkan}
        {--disable : Nonaktifkan kanal tenant ini}';

    protected $description = 'Tetapkan atau nonaktifkan nomor WhatsApp Vada untuk satu tenant';

    public function handle(): int
    {
        $tenant = BaleList::where('slug', $this->argument('tenant'))
            ->orWhere('id', $this->argument('tenant'))
            ->first();

        if ($tenant === null) {
            $this->error('Tenant tidak ditemukan.');

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

        $deviceId = $this->argument('device');

        if (! $deviceId) {
            $this->error('Device wajib diisi kecuali memakai --disable.');

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
                'label' => $this->option('label'),
                'is_active' => true,
            ],
        );

        $this->info("Kanal Vada {$tenant->slug} memakai device {$channel->device_id}.");

        return self::SUCCESS;
    }
}
