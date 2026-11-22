<?php

use Bale\Vada\Models\VadaChannel;
use Bale\Wara\Models\WaraSession;

require_once __DIR__.'/../Support.php';

it('memilih tenant dan device lewat daftar interaktif', function () {
    vadaTenant();

    WaraSession::create([
        'device_id' => 'dev-a',
        'jid' => '628000000001@s.whatsapp.net',
        'display_name' => 'Device Vada',
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ]);

    $this->artisan('vada:assign')
        ->expectsChoice('Pilih tenant', 'Tenant Vada (tenant-vada)', ['Tenant Vada (tenant-vada)'])
        ->expectsChoice('Pilih device WhatsApp', 'dev-a — Device Vada (Siap)', ['dev-a — Device Vada (Siap)'])
        ->expectsQuestion('Label kanal (kosongkan untuk tanpa label)', 'CS Satu')
        ->assertSuccessful();

    $channel = VadaChannel::where('bale_id', VADA_TEST_BALE)->first();

    expect($channel)->not->toBeNull()
        ->and($channel->device_id)->toBe('dev-a')
        ->and($channel->label)->toBe('CS Satu')
        ->and($channel->is_active)->toBeTrue();
});

it('menolak jalan non-interaktif tanpa slug tenant', function () {
    vadaTenant();

    $this->artisan('vada:assign', ['--no-interaction' => true])
        ->expectsOutputToContain('Slug tenant wajib diisi')
        ->assertFailed();
});

it('menonaktifkan kanal tenant lewat --disable', function () {
    vadaTenant();
    $channel = vadaChannel();

    $this->artisan('vada:assign', ['tenant' => 'tenant-vada', '--disable' => true])
        ->assertSuccessful();

    expect($channel->fresh()->is_active)->toBeFalse();
});
