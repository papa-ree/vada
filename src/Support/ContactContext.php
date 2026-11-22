<?php

namespace Bale\Vada\Support;

/**
 * Data yang ditampilkan di panel kanan halaman chat.
 *
 * `name` null berarti provider mengenali nomornya tetapi tidak punya data
 * (mis. belum terdaftar). Vada tetap menampilkan panel supaya petugas tahu
 * keadaannya, bukan menyembunyikannya diam-diam.
 */
final class ContactContext
{
    /**
     * @param  array<string, string>  $fields  label => nilai
     * @param  array<int, ContactHistoryItem>  $history
     * @param  array<int, array{label: string, url: string}>  $actions
     * @param  array{label: string, url: string}|null  $fallbackAction
     * @param  string|null  $panelView  View Blade milik package tenant; dirender di
     *                                  panel setelah tombol aksi. Isinya bebas,
     *                                  termasuk komponen Livewire, sehingga Vada
     *                                  tidak perlu tahu domainnya.
     * @param  array<string, mixed>  $panelData  Data yang diteruskan ke panelView.
     */
    public function __construct(
        public readonly string $source,
        public readonly ?string $name = null,
        public readonly ?string $caption = null,
        /** Token warna badge caption: teal, amber, rose, gray. */
        public readonly string $captionTone = 'gray',
        public readonly array $fields = [],
        public readonly array $history = [],
        public readonly array $actions = [],
        public readonly ?string $historyUrl = null,
        public readonly ?string $emptyMessage = null,
        public readonly ?array $fallbackAction = null,
        public readonly ?string $panelView = null,
        public readonly array $panelData = [],
    ) {}

    public function matched(): bool
    {
        return $this->name !== null;
    }
}
