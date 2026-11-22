<?php

namespace Bale\Vada\Contracts;

use Bale\Vada\Support\ContactContext;

/**
 * Jembatan chat ke layanan tenant.
 *
 * Vada tidak tahu domain apa pun. Setiap package yang ingin menampilkan
 * konteksnya di samping percakapan mendaftarkan implementasi ini, dan Vada
 * hanya merender hasilnya.
 */
interface ContactContextProvider
{
    /** Nama sumber, tampil di panel mis. "Sidulur". */
    public function label(): string;

    /**
     * Konteks untuk satu nomor, kosong bila paket ini tidak mengenalinya.
     *
     * @return array<int, ContactContext>
     */
    public function forPhone(string $phone): array;
}
