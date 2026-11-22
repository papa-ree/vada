<?php

namespace Bale\Vada\Http\Controllers;

use Bale\Cms\Services\TenantManager;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Menyajikan media chat. Dua pintu: admin yang login (tenant aktif) dan URL
 * bertanda tangan berumur pendek untuk GOWA saat mengirim media.
 */
class MediaController
{
    public function __invoke(string $message)
    {
        return $this->stream($message);
    }

    public function signed(string $baleId, string $message)
    {
        TenantManager::initializeFromBaleUuid($baleId);

        try {
            return $this->stream($message);
        } finally {
            TenantManager::clear();
        }
    }

    protected function stream(string $id)
    {
        $message = VadaMessage::findOrFail($id);

        abort_unless(
            $message->media_status === VadaMessage::MEDIA_READY && $message->media_path,
            404
        );

        $disk = Storage::disk(Media::disk());

        // Satu GET saja: exists() dan response() masing-masing memicu HEAD, dan
        // HEAD ke MinIO bisa gagal sesekali walau GET-nya sehat.
        $stream = $disk->readStream($message->media_path);

        // 410: barisnya ada, berkasnya sudah dipurge atau MinIO tidak menjawab.
        abort_unless(is_resource($stream), 410);

        $mime = (string) $message->media_mime;

        return response()->stream(fn () => fpassthru($stream), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                str_starts_with($mime, 'image/') ? 'inline' : 'attachment',
                (string) $message->media_name,
                Str::ascii((string) $message->media_name),
            ),
            'X-Content-Type-Options' => 'nosniff',
            // Isi berkas tidak pernah berubah, jadi browser boleh menyimpannya
            // alih-alih meminta ulang lewat Laravel setiap kali panel dibuka.
            'Cache-Control' => 'private, max-age=604800, immutable',
        ]);
    }
}
