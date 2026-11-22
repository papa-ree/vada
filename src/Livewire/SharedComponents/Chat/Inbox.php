<?php

namespace Bale\Vada\Livewire\SharedComponents\Chat;

use Bale\Cms\Services\TenantConnectionService;
use Bale\Vada\Jobs\MarkVadaConversationRead;
use Bale\Vada\Jobs\SendVadaMessage;
use Bale\Vada\Models\VadaChannel;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Services\ContextRegistry;
use Bale\Vada\Support\Media;
use Bale\Vada\Support\PhoneIndex;
use Bale\Wara\Facades\Wara;
use Bale\Wara\Models\WaraSession;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Ruang kerja chat yang bisa dipasang package tenant mana pun. Layout dan
 * judul halaman milik package pemanggil, bukan milik komponen ini.
 */
class Inbox extends Component
{
    use WithFileUploads;

    public ?string $conversationId = null;

    public string $reply = '';

    public string $search = '';

    /** Jumlah pesan terbaru yang dirender; dinaikkan oleh "Tampilkan chat lama". */
    public int $messageLimit = 30;

    /** @var TemporaryUploadedFile|null */
    public $attachment = null;

    public function mount(): void
    {
        $this->ensureAccess();
    }

    public function selectConversation(string $id): void
    {
        $this->ensureAccess();

        $conversation = VadaConversation::findOrFail($id);

        $this->conversationId = $conversation->id;
        $this->reply = '';
        $this->attachment = null;
        $this->messageLimit = 30;
    }

    public function loadOlder(): void
    {
        $this->ensureAccess();

        if ($this->conversationId !== null) {
            $this->messageLimit += 30;
        }
    }

    /**
     * Panel milik package tenant meminta konteks dihitung ulang (mis. setelah
     * data pemohon berubah). Render ulang saja; `$contexts` dihitung di render.
     */
    #[On('vada:context-refresh')]
    public function refreshContexts(): void
    {
        $this->ensureAccess();
    }

    public function sendReply(?string $reply = null, ?string $replyToId = null): void
    {
        $this->ensureAccess();

        // Alpine mengosongkan textarea lebih dulu untuk bubble optimistis,
        // jadi teksnya datang lewat parameter, bukan properti yang sudah kosong.
        if ($reply !== null) {
            $this->reply = $reply;
        }

        $this->validate([
            'reply' => ['nullable', 'string', 'max:'.config('vada.max_body_length', 4096)],
            'attachment' => [
                'nullable',
                'file',
                'max:'.intdiv(Media::maxBytes(), 1024),
                'mimes:'.implode(',', array_merge(...array_values(Media::EXTENSIONS))),
            ],
            'conversationId' => ['required', 'uuid'],
        ]);

        $body = trim($this->reply);
        $type = $this->attachment
            ? Media::typeFor($this->attachment->getClientOriginalName())
            : null;

        if ($body === '' && $type === null) {
            $this->addError('reply', __('Tulis pesan atau lampirkan berkas.'));

            return;
        }

        if ($this->attachment !== null && $type === null) {
            $this->addError('attachment', __('Jenis berkas tidak didukung.'));

            return;
        }

        $channel = $this->channel();

        if ($channel === null) {
            $this->dispatch('toast', message: __('Nomor WhatsApp tenant belum ditetapkan.'), type: 'error');

            return;
        }

        $conversation = VadaConversation::findOrFail($this->conversationId);

        // Target balasan datang dari request: batasi ke percakapan ini dan
        // bukan tombstone, agar ID dari percakapan lain tidak ikut terkirim.
        $replyTarget = $replyToId !== null ? $this->messageForAction($replyToId) : null;
        $replyToId = $replyTarget !== null && $replyTarget->status !== VadaMessage::STATUS_DELETED
            ? $replyTarget->id
            : null;

        $message = VadaMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => VadaMessage::DIRECTION_OUT,
            'reply_to_id' => $replyToId,
            'body' => $body,
            'status' => VadaMessage::STATUS_QUEUED,
            'sent_by' => auth()->user()->uuid,
            'media_type' => $type,
        ]);

        if ($this->attachment !== null && $type !== null) {
            // Salinan disimpan lebih dulu supaya riwayat tetap bisa diunduh
            // walau URL sementara untuk GOWA sudah kedaluwarsa.
            $stored = Media::save(
                (string) file_get_contents($this->attachment->getRealPath()),
                (string) session('bale_active_uuid'),
                $message->id,
                $this->attachment->getClientOriginalName(),
            );

            $message->forceFill([
                'media_path' => $stored['path'],
                'media_name' => $stored['name'],
                'media_mime' => $stored['mime'],
                'media_size' => $stored['size'],
                'media_status' => VadaMessage::MEDIA_READY,
            ])->save();
        }

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendVadaMessage::dispatch(
            (string) session('bale_active_uuid'),
            $message->id,
            $channel->device_id,
            (string) $conversation->contact_phone,
            $body,
            $type,
        );

        $this->reply = '';
        $this->attachment = null;
    }

    /**
     * "Hapus untuk saya": hilangkan pesan dari Bale saja.
     *
     * Baris di gateway ikut dihapus supaya pesan tidak kembali lewat
     * `vada:sync-history`; kegagalan gateway tidak menahan hapus lokal.
     */
    public function deleteMessageForMe(string $id): void
    {
        $this->ensureAccess();

        $message = $this->messageForAction($id);

        if ($message === null) {
            return;
        }

        $channel = $this->channel();

        try {
            if ($channel !== null && $message->wara_message_id !== null) {
                Wara::deleteMessage(
                    $channel->device_id,
                    (string) $message->wara_message_id,
                    (string) $message->conversation?->contact_phone,
                );
            }
        } catch (Throwable $e) {
            report($e);
        }

        $this->forgetMedia($message);
        $message->delete();

        $this->dispatch('toast', message: __('Pesan dihapus.'), type: 'success');
    }

    /**
     * "Hapus untuk semua": tarik pesan lewat WhatsApp lalu sisakan tombstone,
     * sehingga lawan bicara melihat "pesan ini telah dihapus".
     */
    public function deleteMessageForEveryone(string $id): void
    {
        $this->ensureAccess();

        $message = $this->messageForAction($id);

        if ($message === null || ! $message->canRevoke()) {
            return;
        }

        $channel = $this->channel();

        if ($channel === null) {
            $this->dispatch('toast', message: __('Nomor WhatsApp tenant belum ditetapkan.'), type: 'error');

            return;
        }

        try {
            Wara::revokeMessage(
                $channel->device_id,
                (string) $message->wara_message_id,
                (string) $message->conversation?->contact_phone,
            );
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('toast', message: __('Pesan gagal ditarik dari WhatsApp.'), type: 'error');

            return;
        }

        $this->forgetMedia($message);

        $message->forceFill([
            'body' => '',
            'status' => VadaMessage::STATUS_DELETED,
            'media_type' => null,
            'media_path' => null,
            'media_name' => null,
            'media_mime' => null,
            'media_size' => null,
            'media_status' => null,
        ])->save();

        $this->dispatch('toast', message: __('Pesan ditarik untuk semua orang.'), type: 'success');
    }

    /** Hanya pesan di percakapan yang sedang dibuka; id dari luar ditolak. */
    protected function messageForAction(string $id): ?VadaMessage
    {
        if ($this->conversationId === null) {
            return null;
        }

        return VadaMessage::query()
            ->where('conversation_id', $this->conversationId)
            ->find($id);
    }

    protected function forgetMedia(VadaMessage $message): void
    {
        if ($message->media_path === null) {
            return;
        }

        try {
            Storage::disk(Media::disk())->delete($message->media_path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function render()
    {
        // Livewire update tidak membawa middleware tenant, jadi koneksi
        // dipastikan di sini; tanpa ini query jatuh ke database default.
        TenantConnectionService::ensureAndGetConnection();

        $channel = $this->channel();

        $conversations = VadaConversation::query()
            ->with('latestMessage')
            ->orderByDesc('last_message_at')
            // `contact_phone` terenkripsi, jadi pencarian tidak bisa di SQL.
            // shortcut: hanya 200 percakapan terbaru yang ikut disaring, ganti
            // ke blind index per potongan nomor bila jumlahnya melewati itu.
            ->limit(200)
            ->get();

        if ($this->search !== '') {
            $needle = PhoneIndex::normalize($this->search);

            if ($needle !== null) {
                $conversations = $conversations
                    ->filter(fn (VadaConversation $conversation) => str_contains(
                        (string) PhoneIndex::normalize($conversation->contact_phone),
                        $needle,
                    ))
                    ->values();
            }
        }

        $conversations = $conversations->take(50);

        $active = $this->conversationId
            ? $conversations->firstWhere('id', $this->conversationId)
                ?? VadaConversation::find($this->conversationId)
            : null;

        $rows = $active
            ? $active->messages()->with('replyTo')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit($this->messageLimit + 1)
                ->get()
            : collect();

        $hasOlder = $rows->count() > $this->messageLimit;
        $messages = $rows->take($this->messageLimit)->reverse()->values();

        // Chat yang sedang terbuka dianggap sudah dibaca, sama seperti WhatsApp
        // Web; percakapan aktif juga menjadi pemicu read receipt ke pelanggan.
        if ($active !== null && $active->unread_count > 0) {
            $active->forceFill(['unread_count' => 0])->save();
            $this->sendReadReceipt($active, $channel);
        }

        $contexts = $active
            ? app(ContextRegistry::class)->resolve((string) $active->contact_phone)
            : [];

        return view('vada::livewire.shared-components.chat.inbox', [
            'channel' => $channel,
            'account' => $this->account($channel),
            'conversations' => $conversations,
            'active' => $active,
            'messages' => $messages,
            'hasOlder' => $hasOlder,
            'contexts' => $contexts,
        ]);
    }

    /**
     * Identitas nomor tenant: label kanal + nomor WhatsApp device-nya.
     *
     * @return array{label: string, phone: string}|null
     */
    protected function account(?VadaChannel $channel): ?array
    {
        if ($channel === null) {
            return null;
        }

        $jid = WaraSession::query()
            ->where('device_id', $channel->device_id)
            ->value('jid');

        return [
            'label' => $channel->label ?: $channel->device_id,
            'phone' => PhoneIndex::display($jid),
        ];
    }

    /**
     * Tandai pesan masuk terakhir sudah dibaca di WhatsApp.
     *
     * GOWA hanya punya endpoint per pesan; satu panggilan untuk pesan terakhir
     * menandai percakapan sampai titik itu.
     */
    protected function sendReadReceipt(VadaConversation $conversation, ?VadaChannel $channel): void
    {
        if ($channel === null) {
            return;
        }

        $messageId = $conversation->messages()
            ->where('direction', VadaMessage::DIRECTION_IN)
            ->whereNotNull('wara_message_id')
            ->latest('created_at')
            ->value('wara_message_id');

        if ($messageId === null) {
            return;
        }

        MarkVadaConversationRead::dispatch(
            $channel->device_id,
            (string) $messageId,
            (string) $conversation->contact_phone,
        );
    }

    protected function channel(): ?VadaChannel
    {
        $baleId = session('bale_active_uuid');

        if (! $baleId) {
            return null;
        }

        return VadaChannel::query()
            ->where('bale_id', $baleId)
            ->where('is_active', true)
            ->first();
    }

    protected function ensureAccess(): void
    {
        TenantConnectionService::ensureActive();

        if (! auth()->user()?->can('vada.access')) {
            abort(403, 'Unauthorized action.');
        }
    }
}
