<?php

use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Models\VadaMessage;
use Bale\Vada\Support\ContactContext;
use Bale\Vada\Support\ContactHistoryItem;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    view()->share('errors', new ViewErrorBag);
});

it('merender tiga kolom beserta panel pemohon', function () {
    $conversation = new VadaConversation([
        'contact_phone' => '6285126061182',
        'unread_count' => 2,
        'last_message_at' => now(),
    ]);
    $conversation->id = '11111111-1111-1111-1111-111111111111';
    $conversation->setRelation('latestMessage', null);

    $active = new VadaConversation(['contact_phone' => '6285126061182']);
    $active->id = $conversation->id;

    $context = new ContactContext(
        source: 'Sidulur',
        name: 'Warga Contoh',
        caption: 'Warga aktif',
        captionTone: 'teal',
        fields: ['NIK' => '3502****0001', 'Alamat' => 'Banyudono, Ponorogo'],
        history: [
            new ContactHistoryItem(
                title: 'Surat Domisili',
                meta: 'SDLR-0001 · 12 Nov 2026',
                status: 'Selesai',
                tone: 'teal',
                url: 'https://example.test/pendaftaran/1',
            ),
        ],
        actions: [['label' => 'Tambah layanan', 'url' => 'https://example.test/pendaftaran/create?citizen=1']],
        historyUrl: 'https://example.test/citizen/1',
    );

    $html = view('vada::livewire.shared-components.chat.inbox', [
        'channel' => (object) ['device_id' => 'dev-a'],
        'account' => ['label' => 'Sidulur', 'phone' => '+62 851-2606-1182'],
        'conversations' => collect([$conversation]),
        'active' => $active,
        'messages' => collect(),
        'contexts' => [$context],
        'search' => '',
    ])->render();

    expect($html)
        ->toContain('+62 851-2606-1182')
        ->toContain('Warga Contoh')
        ->toContain('3502****0001')
        ->toContain('Surat Domisili')
        ->toContain('Tambah layanan')
        ->toContain('Lihat semua')
        ->toContain('wire:navigate.hover')
        ->toContain('id="vada-applicant-panel"')
        ->toContain('aria-label="Data pemohon"')
        ->toContain('style="width: var(--vada-panel-w, 24rem)"')
        ->toContain('role="separator"')
        ->toContain('bg-purple-600')
        ->not->toContain("\u{FEFF}")
        ->not->toContain('display: none');
});

it('merender centang sampai dan dibaca untuk pesan keluar', function () {
    $active = new VadaConversation(['contact_phone' => '6285126061182']);
    $active->id = '77777777-7777-7777-7777-777777777777';

    $delivered = new VadaMessage(['direction' => 'out', 'body' => 'sampai', 'status' => VadaMessage::STATUS_DELIVERED]);
    $read = new VadaMessage(['direction' => 'out', 'body' => 'dibaca', 'status' => VadaMessage::STATUS_READ]);

    $html = view('vada::livewire.shared-components.chat.inbox', [
        'channel' => (object) ['device_id' => 'dev-a'],
        'account' => ['label' => 'Sidulur', 'phone' => '+62 851-2606-1182'],
        'conversations' => collect(),
        'active' => $active,
        'messages' => collect([$delivered, $read]),
        'contexts' => [],
        'search' => '',
    ])->render();

    expect($html)
        ->toContain('Sampai')
        ->toContain('Dibaca')
        ->toContain('text-sky-600');
});

it('merender kutipan untuk balasan pesan masuk', function () {
    $active = new VadaConversation(['contact_phone' => '6285126061182']);
    $active->id = '88888888-8888-8888-8888-888888888888';

    $quoted = new VadaMessage(['direction' => 'out', 'body' => 'Pesan yang dikutip', 'status' => VadaMessage::STATUS_SENT]);
    $reply = new VadaMessage(['direction' => 'in', 'body' => 'Balasan kontak', 'status' => VadaMessage::STATUS_RECEIVED]);
    $reply->id = '99999999-9999-9999-9999-999999999999';
    $reply->setRelation('replyTo', $quoted);

    $html = view('vada::livewire.shared-components.chat.inbox', [
        'channel' => (object) ['device_id' => 'dev-a'],
        'account' => ['label' => 'Sidulur', 'phone' => '+62 851-2606-1182'],
        'conversations' => collect(),
        'active' => $active,
        'messages' => collect([$reply]),
        'contexts' => [],
        'search' => '',
    ])->render();

    expect($html)
        ->toContain('Balasan kontak')
        ->toContain('Pesan yang dikutip');
});

it('merender keadaan nomor belum terdaftar beserta CTA', function () {
    $context = new ContactContext(
        source: 'Sidulur',
        emptyMessage: 'Nomor ini belum terdaftar sebagai warga.',
        fallbackAction: ['label' => 'Daftarkan warga', 'url' => 'https://example.test/citizen/form?phone=6285126061182'],
    );

    $active = new VadaConversation(['contact_phone' => '6285126061182']);
    $active->id = '22222222-2222-2222-2222-222222222222';

    $html = view('vada::livewire.shared-components.chat.inbox', [
        'channel' => (object) ['device_id' => 'dev-a'],
        'account' => ['label' => 'Sidulur', 'phone' => '+62 851-2606-1182'],
        'conversations' => collect(),
        'active' => $active,
        'messages' => collect(),
        'contexts' => [$context],
        'search' => '',
    ])->render();

    expect($html)
        ->toContain('Nomor ini belum terdaftar sebagai warga.')
        ->toContain('Daftarkan warga')
        ->toContain('phone=6285126061182');
});

it('merender gambar, media pending, dan media gagal di thread', function () {
    $active = new VadaConversation(['contact_phone' => '6285126061182']);
    $active->id = '33333333-3333-3333-3333-333333333333';

    $image = new VadaMessage([
        'direction' => 'in',
        'body' => '',
        'media_type' => 'image',
        'media_path' => 'vada/x/foto.jpg',
        'media_name' => 'foto.jpg',
        'media_mime' => 'image/jpeg',
        'media_status' => VadaMessage::MEDIA_READY,
    ]);
    $image->id = '44444444-4444-4444-4444-444444444444';
    $image->created_at = now();

    $pending = new VadaMessage(['direction' => 'in', 'body' => '', 'media_type' => 'document', 'media_status' => VadaMessage::MEDIA_PENDING]);
    $pending->id = '55555555-5555-5555-5555-555555555555';

    $failed = new VadaMessage(['direction' => 'in', 'body' => '', 'media_type' => 'video', 'media_status' => VadaMessage::MEDIA_FAILED]);
    $failed->id = '66666666-6666-6666-6666-666666666666';

    $html = view('vada::livewire.shared-components.chat.inbox', [
        'channel' => (object) ['device_id' => 'dev-a'],
        'account' => ['label' => 'Sidulur', 'phone' => '+62 851-2606-1182'],
        'conversations' => collect(),
        'active' => $active,
        'messages' => collect([$image, $pending, $failed]),
        'contexts' => [],
        'search' => '',
    ])->render();

    expect($html)
        ->toContain('/vada/media/44444444-4444-4444-4444-444444444444')
        ->toContain('<img')
        ->toContain('Mengunduh media')
        ->toContain('Media belum didukung')
        ->not->toContain('&lt;img');
});
