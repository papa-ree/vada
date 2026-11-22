<?php

namespace Bale\Vada\Support;

/**
 * Satu baris riwayat dari layanan tenant (mis. pengajuan Sidulur).
 */
final class ContactHistoryItem
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $meta = null,
        public readonly ?string $status = null,
        /** Token warna: teal, amber, rose, gray. View yang memetakan ke kelas. */
        public readonly string $tone = 'gray',
        public readonly ?string $url = null,
    ) {}
}
