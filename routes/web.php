<?php

use Bale\Cms\Middleware\EnsureBaleSelected;
use Bale\Cms\Middleware\SwitchBaleConnection;
use Bale\Vada\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

// Admin: tenant aktif datang dari sesi.
Route::middleware(['web', 'auth', EnsureBaleSelected::class, SwitchBaleConnection::class, 'can:vada.access'])
    ->get('vada/media/{message}', MediaController::class)
    ->name('vada.media');

// GOWA: tanpa sesi login, jadi tenant dibawa di URL dan diverifikasi signature.
// `{filename}` wajib ada di akhir: GOWA menentukan jenis berkas dari ekstensi
// segmen terakhir URL, bukan dari Content-Type.
Route::middleware(['web', 'signed'])
    ->get('vada/media/{baleId}/{message}/signed/{filename}', [MediaController::class, 'signed'])
    ->name('vada.media.signed');
