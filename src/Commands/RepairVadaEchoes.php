<?php

namespace Bale\Vada\Commands;

use Bale\Cms\Models\BaleList;
use Bale\Cms\Services\TenantManager;
use Bale\Vada\Models\VadaChannel;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\PhoneIndex;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Illuminate\Console\Command;
use Throwable;

/**
 * Memindahkan pesan lama yang tercatat di percakapan salah.
 *
 * Sebelum percakapan mengikuti `chat_id`, pesan yang dikirim dari HP memakai
 * `from` (nomor sendiri) sehingga menumpuk di satu percakapan "nomor sendiri".
 * `wara_logs.chat_id` menyimpan lawan bicara sebenarnya untuk tiap pesan.
 */
class RepairVadaEchoes extends Command
{
    protected $signature = 'vada:repair-echoes
        {--tenant= : Slug tenant (tanpa ini: semua tenant)}
        {--dry-run : Hitung saja tanpa mengubah data}';

    protected $description = 'Pindahkan echo pesan lama dari percakapan "nomor sendiri" ke percakapan lawan bicara';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $tenants = BaleList::query()
            ->when($this->option('tenant'), fn ($query, $slug) => $query->where('slug', $slug))
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('Tidak ada tenant yang cocok.');

            return self::FAILURE;
        }

        $moved = 0;
        $dropped = 0;

        foreach ($tenants as $tenant) {
            $ownPhones = $this->ownPhones($tenant->id);

            if ($ownPhones === []) {
                continue;
            }

            try {
                TenantManager::initializeFromBaleUuid($tenant->id);

                $conversations = VadaConversation::query()->get()
                    ->filter(fn (VadaConversation $conversation) => in_array(
                        PhoneIndex::normalize($conversation->contact_phone),
                        $ownPhones,
                        true
                    ));

                foreach ($conversations as $conversation) {
                    foreach ($conversation->messages()->get() as $message) {
                        if ($message->wara_message_id === null) {
                            continue;
                        }

                        $log = WaraLog::query()->where('message_id', $message->wara_message_id)->first();
                        $target = $this->chatPhone($log?->chat_id);

                        if ($target === null || in_array($target, $ownPhones, true)) {
                            continue;
                        }

                        $targetConversation = VadaConversation::forPhone($target);

                        if ($targetConversation->is($conversation)) {
                            continue;
                        }

                        $duplicate = VadaMessage::query()
                            ->where('conversation_id', $targetConversation->id)
                            ->where('wara_message_id', $message->wara_message_id)
                            ->exists();

                        if (! $dryRun) {
                            if ($duplicate) {
                                $message->delete();
                            } else {
                                $message->forceFill([
                                    'conversation_id' => $targetConversation->id,
                                    'direction' => VadaMessage::DIRECTION_OUT,
                                    'status' => VadaMessage::STATUS_SENT,
                                    'media_type' => null,
                                    'media_path' => null,
                                    'media_name' => null,
                                    'media_mime' => null,
                                    'media_size' => null,
                                    'media_status' => null,
                                ])->save();

                                if ($targetConversation->last_message_at === null
                                    || $message->created_at->gt($targetConversation->last_message_at)) {
                                    $targetConversation->forceFill(['last_message_at' => $message->created_at])->save();
                                }
                            }
                        }

                        $duplicate ? $dropped++ : $moved++;
                    }

                    if (! $dryRun && $conversation->messages()->count() === 0) {
                        $conversation->delete();
                    }
                }
            } catch (Throwable $e) {
                $this->error("Tenant {$tenant->slug} gagal: {$e->getMessage()}");
            } finally {
                TenantManager::clear();
            }
        }

        $verb = $dryRun ? 'Akan dipindahkan' : 'Dipindahkan';

        $this->info("{$verb}: {$moved} pesan; duplikat dihapus: {$dropped}.");

        return self::SUCCESS;
    }

    /**
     * Nomor device tenant, untuk mengenali percakapan "nomor sendiri".
     *
     * @return list<string>
     */
    protected function ownPhones(string $baleId): array
    {
        $devices = VadaChannel::query()->where('bale_id', $baleId)->pluck('device_id');

        return WaraSession::query()
            ->whereIn('device_id', $devices)
            ->pluck('jid')
            ->map(fn ($jid) => PhoneIndex::normalize($jid))
            ->filter()
            ->values()
            ->all();
    }

    protected function chatPhone(?string $chatId): ?string
    {
        if ($chatId === null || $chatId === '') {
            return null;
        }

        $at = strpos($chatId, '@');
        $suffix = $at === false ? '' : strtolower(substr($chatId, $at));

        if ($suffix !== '' && $suffix !== '@s.whatsapp.net') {
            return null;
        }

        $user = $at === false ? $chatId : substr($chatId, 0, $at);
        $colon = strpos($user, ':');

        if ($colon !== false) {
            $user = substr($user, 0, $colon);
        }

        return PhoneIndex::normalize($user);
    }
}
