<?php

namespace Bale\Vada\Commands;

use Bale\Cms\Models\BaleList;
use Bale\Cms\Services\TenantManager;
use Bale\Vada\Jobs\FetchVadaMedia;
use Bale\Vada\Models\VadaChannel;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\PhoneIndex;
use Bale\Wara\Facades\Wara;
use Bale\Wara\Models\WaraSession;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mengimpor riwayat chat yang tersimpan di chat storage gateway.
 *
 * Sumbernya GOWA, bukan server WhatsApp: hanya pesan yang pernah dilihat
 * gateway sejak device dipasangkan. Pesan yang sudah ada (dari webhook atau
 * job balasan) dilewati lewat `wara_message_id`, jadi perintah ini aman diulang.
 */
class SyncVadaHistory extends Command
{
    protected const PAGE = 100;

    protected const DOWNLOADABLE = ['image', 'audio', 'document'];

    protected $signature = 'vada:sync-history
        {--tenant= : Slug tenant (tanpa ini: semua tenant)}
        {--days=30 : Batas umur pesan dalam hari; 0 = semua yang ada di gateway}
        {--dry-run : Hitung saja tanpa mengubah data}';

    protected $description = 'Impor riwayat chat lama dari gateway ke database tenant';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $since = $days > 0 ? now()->subDays($days) : null;
        $dryRun = (bool) $this->option('dry-run');

        $tenants = BaleList::query()
            ->when($this->option('tenant'), fn ($query, $slug) => $query->where('slug', $slug))
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('Tidak ada tenant yang cocok.');

            return self::FAILURE;
        }

        $imported = 0;
        $queued = 0;

        foreach ($tenants as $tenant) {
            $channel = VadaChannel::query()
                ->where('bale_id', $tenant->id)
                ->where('is_active', true)
                ->first();

            if ($channel === null) {
                continue;
            }

            $pending = [];

            try {
                TenantManager::initializeFromBaleUuid($tenant->id);

                $imported += $this->syncDevice($tenant->id, $channel->device_id, $since, $dryRun, $pending);
            } catch (Throwable $e) {
                $this->error("Tenant {$tenant->slug} gagal: {$e->getMessage()}");
            } finally {
                TenantManager::clear();
            }

            // Job butuh koneksi tenant yang bersih; mengantre di dalam konteks
            // tenant membuat worker `sync` menghapus koneksi command ini.
            foreach ($pending as $job) {
                FetchVadaMedia::dispatch(...$job);
                $queued++;
            }
        }

        $verb = $dryRun ? 'Akan diimpor' : 'Diimpor';

        $this->info("{$verb}: {$imported} pesan; media diantrekan: {$queued}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $pending
     */
    protected function syncDevice(
        string $baleId,
        string $deviceId,
        ?CarbonInterface $since,
        bool $dryRun,
        array &$pending,
    ): int {
        $ownPhone = PhoneIndex::normalize(
            WaraSession::query()->where('device_id', $deviceId)->value('jid')
        );

        $imported = 0;
        $offset = 0;

        do {
            $chats = Wara::chats($deviceId, self::PAGE, $offset);

            foreach ($chats as $chat) {
                if ($chat->phone === null || $chat->phone === $ownPhone) {
                    continue;
                }

                $imported += $this->syncChat($baleId, $deviceId, $chat->phone, $since, $dryRun, $pending);
            }

            $offset += self::PAGE;
        } while ($chats->count() === self::PAGE);

        return $imported;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $pending
     */
    protected function syncChat(
        string $baleId,
        string $deviceId,
        string $phone,
        ?CarbonInterface $since,
        bool $dryRun,
        array &$pending,
    ): int {
        $conversation = null;
        $lastAt = null;
        $imported = 0;
        $offset = 0;

        do {
            $messages = Wara::chatMessages($deviceId, $phone, $since, self::PAGE, $offset);

            foreach ($messages as $message) {
                // `call` adalah log panggilan, bukan pesan yang bisa dibalas.
                if ($message->id === '' || $message->mediaType === 'call') {
                    continue;
                }

                if (VadaMessage::query()->where('wara_message_id', $message->id)->exists()) {
                    continue;
                }

                $imported++;

                if ($dryRun) {
                    continue;
                }

                $conversation ??= VadaConversation::forPhone($phone);
                $at = $message->timestamp ?? now();
                $lastAt = $lastAt === null || $at->greaterThan($lastAt) ? $at : $lastAt;

                // Media milik sendiri tidak diunduh, sama seperti aturan echo.
                $download = ! $message->isFromMe
                    && in_array($message->mediaType, self::DOWNLOADABLE, true);

                $row = new VadaMessage([
                    'conversation_id' => $conversation->id,
                    'direction' => $message->isFromMe ? VadaMessage::DIRECTION_OUT : VadaMessage::DIRECTION_IN,
                    'wara_message_id' => $message->id,
                    'body' => $message->body,
                    'status' => $message->isFromMe ? VadaMessage::STATUS_SENT : VadaMessage::STATUS_RECEIVED,
                    'media_type' => $message->mediaType,
                    'media_name' => $message->mediaType === null ? null : $message->mediaName,
                    'media_status' => match (true) {
                        $message->mediaType === null => null,
                        $download => VadaMessage::MEDIA_PENDING,
                        default => VadaMessage::MEDIA_FAILED,
                    },
                ]);

                $row->created_at = $at;
                $row->updated_at = $at;
                $row->save();

                if ($download) {
                    $pending[] = [$baleId, $row->id, $deviceId, $phone, $message->id];
                }
            }

            $offset += self::PAGE;
        } while ($messages->count() === self::PAGE);

        if (! $dryRun && $conversation !== null && $lastAt !== null
            && ($conversation->last_message_at === null || $lastAt->greaterThan($conversation->last_message_at))) {
            $conversation->forceFill(['last_message_at' => $lastAt])->save();
        }

        return $imported;
    }
}
