<?php

namespace Bale\Vada\Support;

use Bale\Core\Services\ImageService;
use Bale\Vada\Models\VadaMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Satu-satunya tempat yang memutuskan jenis media, ukuran, dan lokasi
 * penyimpanan. Dipakai jalur masuk (job) dan jalur keluar (composer).
 */
final class Media
{
    /** Allowlist per jenis; ekstensi lain ditolak. Video & stiker ditunda. */
    public const EXTENSIONS = [
        // `jfif`: GOWA menamai hasil unduhan JPEG dengan ekstensi ini.
        'image' => ['jpg', 'jpeg', 'jfif', 'png', 'webp'],
        'audio' => ['ogg', 'mp3'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
    ];

    public const MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jfif' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'ogg' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public static function disk(): string
    {
        return (string) config('vada.media.disk', 'vada');
    }

    public static function maxBytes(): int
    {
        return (int) config('vada.media.max_kb', 16384) * 1024;
    }

    public static function typeFor(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        foreach (self::EXTENSIONS as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $type;
            }
        }

        return null;
    }

    public static function allows(?string $type, string $filename): bool
    {
        return $type !== null && self::typeFor($filename) === $type;
    }

    public static function mimeFor(string $filename): string
    {
        return self::MIMES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /**
     * Nama objek mempertahankan nama asli (sudah dibersihkan) karena GOWA
     * mengambil nama dokumen yang dikirim dari segmen terakhir URL.
     *
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public static function save(string $contents, string $baleId, string $messageId, string $filename): array
    {
        $name = self::sanitize($filename);
        $type = self::typeFor($name);

        $webp = $type === 'image' ? self::toWebp($contents) : null;

        if ($webp !== null) {
            $name = pathinfo($name, PATHINFO_FILENAME).'.webp';
            $contents = $webp;
        }

        $path = 'vada/'.$baleId.'/'.$messageId.'/'.$name;

        Storage::disk(self::disk())->put($path, $contents);

        return [
            'path' => $path,
            'name' => $filename,
            'mime' => self::mimeFor($name),
            'size' => strlen($contents),
        ];
    }

    /**
     * Gambar chat dikompres ke WebP. Berkas rusak atau sangat besar dibiarkan
     * apa adanya supaya pesan tetap tersimpan.
     */
    protected static function toWebp(string $contents): ?string
    {
        $size = @getimagesizefromstring($contents);

        // shortcut: gambar di atas 20 MP tidak dikonversi agar worker tidak kehabisan memori; naikkan bila memori worker mencukupi.
        if (is_array($size) && $size[0] * $size[1] > 20_000_000) {
            return null;
        }

        try {
            $encoded = app(ImageService::class)->encodeWebp($contents, 80, 1600);
        } catch (Throwable) {
            return null;
        }

        return $encoded !== null ? (string) $encoded : null;
    }

    /** URL untuk admin yang sedang login di tenant aktif. */
    public static function url(VadaMessage $message): ?string
    {
        if (! $message->media_path) {
            return null;
        }

        return route('vada.media', $message->getKey());
    }

    /**
     * URL berumur pendek yang bisa diambil GOWA tanpa sesi login. Dipakai
     * untuk mengirim media, karena GOWA hanya menerima URL.
     *
     * Nama berkas ada di segmen terakhir: GOWA menolak gambar bila ekstensi
     * URL-nya tidak `.jpg/.jpeg/.png/.webp`, dan `Content-Type` saja tidak
     * dipakai untuk itu.
     */
    public static function signedUrl(VadaMessage $message, string $baleId): ?string
    {
        if (! $message->media_path) {
            return null;
        }

        return URL::temporarySignedRoute('vada.media.signed', now()->addMinutes(10), [
            'baleId' => $baleId,
            'message' => $message->getKey(),
            // Ekstensi mengikuti berkas tersimpan (`.webp` untuk gambar baru),
            // bukan nama asli yang dipertahankan untuk label.
            'filename' => self::sanitize(basename((string) $message->media_path)),
        ]);
    }

    public static function sanitize(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? '';
        $filename = trim($filename, '._-');

        if ($filename === '') {
            $filename = 'media';
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';

        return Str::limit(pathinfo($filename, PATHINFO_FILENAME), 80, '').'.'.$extension;
    }
}
