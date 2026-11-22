<?php

namespace Bale\Vada\Models;

use Bale\Cms\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VadaMessage extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    /** Tombstone setelah "hapus untuk semua"; isi dan media sudah dikosongkan. */
    public const STATUS_DELETED = 'deleted';

    public const MEDIA_PENDING = 'pending';

    public const MEDIA_READY = 'ready';

    public const MEDIA_FAILED = 'failed';

    public const MEDIA_EXPIRED = 'expired';

    protected $table = 'vada_messages';

    protected $fillable = [
        'conversation_id',
        'direction',
        'reply_to_id',
        'wara_message_id',
        'body',
        'status',
        'error',
        'sent_by',
        'media_type',
        'media_path',
        'media_name',
        'media_mime',
        'media_size',
        'media_status',
    ];

    protected $hidden = ['body'];

    protected $casts = [
        'body' => 'encrypted',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(VadaConversation::class, 'conversation_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function isOutgoing(): bool
    {
        return $this->direction === self::DIRECTION_OUT;
    }

    public function hasMedia(): bool
    {
        return $this->media_type !== null;
    }

    public function isImage(): bool
    {
        return $this->media_type === 'image';
    }

    /** WhatsApp hanya mengizinkan penarikan pesan milik sendiri yang sudah terkirim. */
    public function canRevoke(): bool
    {
        return $this->isOutgoing()
            && $this->wara_message_id !== null
            && in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_READ], true);
    }

    /** Label singkat untuk preview daftar percakapan. */
    public function mediaLabel(): string
    {
        return match ($this->media_type) {
            'image' => __('Gambar'),
            'audio' => __('Audio'),
            'document' => $this->media_name ?: __('Dokumen'),
            default => __('Media'),
        };
    }
}
