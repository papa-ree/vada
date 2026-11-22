<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kunci blind index nomor WhatsApp
    |--------------------------------------------------------------------------
    |
    | Dipakai untuk hash HMAC `contact_phone_hash`. Nilainya harus stabil:
    | mengganti kunci membuat percakapan lama tidak lagi ditemukan per nomor.
    | Kosong = memakai `app.key`.
    |
    */
    'index_key' => env('VADA_INDEX_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Antrean pengiriman balasan
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('VADA_QUEUE_CONNECTION'),
        'name' => env('VADA_QUEUE_NAME', 'default'),
        'tries' => (int) env('VADA_QUEUE_TRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling halaman chat
    |--------------------------------------------------------------------------
    */
    'poll_seconds' => (int) env('VADA_POLL_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Batas pesan
    |--------------------------------------------------------------------------
    */
    'max_body_length' => (int) env('VADA_MAX_BODY_LENGTH', 4096),

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | Disimpan sebagai salinan milik Bale (bucket privat terpisah di produksi,
    | disk lokal saat pengembangan). Video dan stiker belum didukung.
    |
    */
    'media' => [
        'disk' => env('VADA_MEDIA_DISK', 'vada'),
        'bucket' => env('VADA_MEDIA_BUCKET'),
        'max_kb' => (int) env('VADA_MEDIA_MAX_KB', 16384),
        'prune_days' => (int) env('VADA_MEDIA_PRUNE_DAYS', 100),
    ],

];
