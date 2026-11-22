<?php

namespace Bale\Vada\Models;

use Bale\Cms\Traits\UsesTenantConnection;
use Bale\Vada\Support\PhoneIndex;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VadaConversation extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'vada_conversations';

    protected $fillable = [
        'contact_phone',
        'contact_phone_hash',
        'last_message_at',
        'unread_count',
        'status',
    ];

    protected $hidden = ['contact_phone', 'contact_phone_hash'];

    protected $casts = [
        'contact_phone' => 'encrypted',
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(VadaMessage::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(VadaMessage::class, 'conversation_id')->latestOfMany('created_at');
    }

    public static function forPhone(string $phone): self
    {
        $normalized = PhoneIndex::normalize($phone);
        $hash = PhoneIndex::make($phone);

        return static::firstOrCreate(
            ['contact_phone_hash' => $hash],
            ['contact_phone' => $normalized],
        );
    }
}
