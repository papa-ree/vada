<?php

namespace Bale\Vada\Support;

/**
 * Pencarian nomor pada kolom terenkripsi memakai HMAC deterministik.
 *
 * Nomor dinormalisasi ke format internasional `62...` supaya `08xx`, `8xx`,
 * dan `+62xx` menghasilkan hash yang sama.
 */
class PhoneIndex
{
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }

    /**
     * Nomor untuk ditampilkan: `+62 851-2606-1182`.
     *
     * Nomor tersimpan sebagai `62...`; tampilan memakai format yang sama dengan
     * WhatsApp Web supaya admin mengenali nomornya, bukan deretan angka.
     */
    public static function display(?string $value): string
    {
        $digits = static::normalize($value);

        if ($digits === null) {
            return '—';
        }

        $local = str_starts_with($digits, '62') ? substr($digits, 2) : $digits;

        if ($local === '') {
            return '—';
        }

        $head = substr($local, 0, 3);
        $rest = trim(chunk_split(substr($local, 3), 4, '-'), '-');

        return '+62 '.$head.($rest !== '' ? '-'.$rest : '');
    }

    public static function make(?string $value): ?string
    {
        $normalized = static::normalize($value);

        if ($normalized === null) {
            return null;
        }

        return hash_hmac('sha256', $normalized, static::key());
    }

    protected static function key(): string
    {
        return (string) (config('vada.index_key') ?: config('app.key'));
    }
}
