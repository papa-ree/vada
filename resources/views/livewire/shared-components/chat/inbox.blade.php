{{-- Ruang kerja chat. Tiga kolom saat lebar cukup, dua kolom di tablet, satu
     kolom bergantian di ponsel. Panel pemohon menjadi drawer di bawah 1280px. --}}
<div wire:poll.{{ config('vada.poll_seconds', 5) }}s
     @keydown.escape.window="menu.open = false"
     @contextmenu="if (! $event.target.closest('[data-vada-message]')) { menu.open = false }"
     class="-mx-4 -mt-5 -mb-10 flex h-[calc(100dvh-var(--cms-topbar-h,4.3125rem))] overflow-hidden bg-white dark:bg-gray-900 sm:-mx-6 md:-mx-8"
     x-data="{
        selected: @js($active?->id),
        mobileOpen: @js($active !== null),
        panelOpen: false,
        panelWidth: Math.min(640, Math.max(320, Number(localStorage.getItem('vada.panelWidth')) || 384)),
        panelResizing: false,
        atBottom: true,
        quiet: false,
        quietTimer: null,
        pending: [],
        pendingSeq: 0,
        replyTo: null,
        menu: { open: false, x: 0, y: 0, id: null, revocable: false, deleted: false, author: '', snippet: '' },
        openMenu(event, row) {
            this.menu = {
                open: true,
                // Jepit ke viewport supaya menu tidak keluar layar di tepi kanan/bawah.
                x: Math.max(8, Math.min(event.clientX, window.innerWidth - 200)),
                y: Math.max(8, Math.min(event.clientY, window.innerHeight - 132)),
                id: row.dataset.vadaMessage,
                revocable: row.dataset.revocable === '1',
                deleted: row.dataset.deleted === '1',
                author: row.dataset.author,
                snippet: row.dataset.snippet,
            };
        },
        onContextMenu(event) {
            const row = event.target.closest('[data-vada-message]');
            if (row === null) {
                this.menu.open = false;
                return;
            }
            this.openMenu(event, row);
        },
        startReply(row) {
            this.replyTo = {
                id: row.dataset.vadaMessage,
                author: row.dataset.author,
                snippet: row.dataset.snippet,
            };
        },
        replyFromMenu() {
            this.replyTo = { id: this.menu.id, author: this.menu.author, snippet: this.menu.snippet };
            this.menu.open = false;
        },
        async open(id) {
            this.mobileOpen = true;
            if (this.selected === id) {
                this.pin();
                return;
            }
            this.selected = id;
            this.pending = [];
            this.replyTo = null;
            this.menu.open = false;
            try {
                await this.$wire.selectConversation(id);
            } finally {
                // Setelah morph selesai: lompat ke pesan terbawah sekaligus,
                // sebelum browser sempat melukis posisi atas.
                this.$nextTick(() => this.pin());
            }
        },
        back() {
            this.mobileOpen = false;
        },
        openPanel() {
            this.panelOpen = true;
            this.$nextTick(() => this.$refs.panel?.focus());
        },
        closePanel() {
            this.panelOpen = false;
            this.$nextTick(() => this.$refs.panelTrigger?.focus());
        },
        // Lebar panel kanan disimpan di CSS variable pada <html> supaya tidak
        // ikut terhapus saat morph wire:poll.
        setPanelWidth(width, persist = false) {
            this.panelWidth = Math.min(640, Math.max(320, Math.round(width)));
            document.documentElement.style.setProperty('--vada-panel-w', this.panelWidth + 'px');
            if (persist) { localStorage.setItem('vada.panelWidth', String(this.panelWidth)); }
        },
        resizePanel(event) {
            this.panelResizing = true;
            event.currentTarget.setPointerCapture(event.pointerId);
            this.setPanelWidth(window.innerWidth - event.clientX);
        },
        dragPanel(event) {
            if (this.panelResizing) { this.setPanelWidth(window.innerWidth - event.clientX); }
        },
        endPanelResize(event) {
            if (! this.panelResizing) { return; }
            this.panelResizing = false;
            if (event.currentTarget.hasPointerCapture?.(event.pointerId)) {
                event.currentTarget.releasePointerCapture(event.pointerId);
            }
            this.setPanelWidth(this.panelWidth, true);
        },
        nudgePanel(delta) {
            this.setPanelWidth(this.panelWidth + delta, true);
        },
        grow(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 140) + 'px';
        },
        scrollToBottom() {
            if (! this.$refs.thread) { return; }
            this.$refs.thread.scrollTop = this.$refs.thread.scrollHeight;
        },
        // Satu-satunya jalur scroll programatik: lompat langsung tanpa animasi,
        // lalu abaikan geseran yang dipicu morph sebelum pengguna menyentuh layar.
        pin() {
            this.scrollToBottom();
            this.atBottom = true;
            this.quiet = true;
            clearTimeout(this.quietTimer);
            this.quietTimer = setTimeout(() => {
                this.quiet = false;
                this.trackScroll();
            }, 150);
        },
        jumpToBottom() {
            this.pin();
        },
        // Muat pesan lama tanpa menggeser posisi baca: selisih tinggi thread
        // ditambahkan ke scrollTop setelah morph selesai.
        async loadOlder() {
            const el = this.$refs.thread;
            if (! el) { return; }
            const previousHeight = el.scrollHeight;
            const previousTop = el.scrollTop;
            this.atBottom = false;
            this.quiet = true;
            try {
                await this.$wire.loadOlder();
            } finally {
                this.$nextTick(() => {
                    el.scrollTop = el.scrollHeight - previousHeight + previousTop;
                    clearTimeout(this.quietTimer);
                    this.quietTimer = setTimeout(() => {
                        this.quiet = false;
                        this.trackScroll();
                    }, 150);
                });
            }
        },
        trackScroll() {
            if (this.quiet) { return; }
            const el = this.$refs.thread;
            if (! el) { return; }
            this.atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 64;
        },
        async send(form) {
            const field = form.querySelector('textarea');
            const fileInput = form.querySelector('input[type=file]');
            const file = fileInput?.files?.[0]?.name ?? null;
            const text = field.value.trim();

            if (text === '' && file === null && ! this.$wire.attachment) { return; }

            // Berkas dipilih tapi unggahan belum selesai: jangan kirim teks
            // saja, karena lampirannya akan hilang tanpa jejak.
            if (file !== null && ! this.$wire.attachment) { return; }

            const id = ++this.pendingSeq;
            this.pending.push({ id, body: text, file, at: new Date() });

            field.value = '';
            this.grow(field);
            if (fileInput) { fileInput.value = ''; }
            this.$nextTick(() => this.jumpToBottom());

            try {
                await this.$wire.sendReply(text, this.replyTo?.id ?? null);
                this.replyTo = null;

                // Validasi/kanal ditolak: server menyimpan teks di `reply`.
                // Kembalikan hanya bila pengguna belum mengetik pesan baru.
                const restored = this.$wire.reply;
                if (restored && field.value === '') {
                    field.value = restored;
                    this.grow(field);
                }
            } catch (e) {
                // Jaringan putus sebelum server menerima; jangan kehilangan teks.
                field.value = text;
                this.grow(field);
            } finally {
                this.pending = this.pending.filter((p) => p.id !== id);
            }
        },
        init() {
            this.setPanelWidth(this.panelWidth);

            // Langsung di dasar sebelum paint pertama: tidak ada animasi
            // gulir dari atas saat ruang chat dibuka.
            this.pin();
            this.$nextTick(() => this.pin());
            requestAnimationFrame(() => this.pin());

            // Morph wire:poll mengganti isi thread. Ikut ke dasar hanya bila
            // pengguna sedang di dasar, atau pesan baru keluar dari pengguna.
            new MutationObserver((records) => {
                const outgoing = records.some((record) => [...record.addedNodes].some(
                    (node) => node.nodeType === 1
                        && (node.matches('[data-outgoing]') || node.querySelector('[data-outgoing]'))));

                if (this.atBottom || outgoing) { this.pin(); }
            }).observe(this.$el, { childList: true, subtree: true });
        },
     }">

    <h1 class="sr-only">{{ __('Chat') }}</h1>

    @if(! $channel)
        <div class="flex flex-1 flex-col items-center justify-center px-6 text-center">
            <x-lucide-message-circle class="size-8 text-gray-300 dark:text-gray-600" />
            <p class="mt-3 text-sm font-medium text-gray-800 dark:text-gray-100">
                {{ __('Nomor WhatsApp belum ditetapkan.') }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Jalankan vada:assign untuk tenant ini.') }}
            </p>
        </div>
    @else
        {{-- Daftar percakapan --}}
        <aside class="min-h-0 w-full flex-col border-r border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 lg:flex lg:w-[21rem] lg:shrink-0"
               :class="mobileOpen ? 'max-lg:hidden' : 'flex'"
               aria-label="{{ __('Daftar percakapan') }}">

            <header class="flex h-14 shrink-0 items-center gap-3 border-b border-gray-200 px-4 dark:border-white/10">
                <span class="relative grid size-9 shrink-0 place-items-center rounded-full bg-purple-600 text-xs font-semibold text-white">
                    {{ mb_strtoupper(mb_substr($account['label'], 0, 2)) }}
                    <span class="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-gray-900" aria-hidden="true"></span>
                    <span class="sr-only">{{ __('Nomor aktif menerima pesan') }}</span>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13px] font-semibold text-gray-900 dark:text-white">{{ $account['label'] }}</p>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $account['phone'] }}</p>
                </div>
            </header>

            <div class="relative shrink-0 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                <label for="vada-search" class="sr-only">{{ __('Cari nomor WhatsApp') }}</label>
                <x-lucide-search class="pointer-events-none absolute top-1/2 left-5.5 size-4 -translate-y-1/2 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                <input id="vada-search"
                       type="search"
                       wire:model.live.debounce.300ms="search"
                       autocomplete="off"
                       placeholder="{{ __('Cari nomor') }}"
                       class="block w-full rounded-lg border border-transparent bg-gray-100 py-2 pr-3 pl-9 text-[13px] text-gray-900 placeholder-gray-600 transition-colors focus:border-purple-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-purple-500/25 dark:bg-white/5 dark:text-gray-100 dark:placeholder-gray-400 dark:focus:bg-gray-900">
            </div>

            <div wire:loading wire:target="search" class="flex-1 space-y-3 p-3" aria-hidden="true">
                @for($i = 0; $i < 6; $i++)
                    <div class="flex items-center gap-3">
                        <div class="size-9 shrink-0 animate-pulse rounded-full bg-gray-100 dark:bg-white/10"></div>
                        <div class="flex-1 space-y-2">
                            <div class="h-2.5 w-24 animate-pulse rounded bg-gray-100 dark:bg-white/10"></div>
                            <div class="h-2.5 w-40 animate-pulse rounded bg-gray-100 dark:bg-white/10"></div>
                        </div>
                    </div>
                @endfor
            </div>

            <div wire:loading.remove wire:target="search" class="min-h-0 flex-1 overflow-y-auto overscroll-contain scrollbar-thin scrollbar-thumb-gray-400 dark:scrollbar-thumb-gray-600 scrollbar-track-gray-200 dark:scrollbar-track-gray-800 scrollbar-thumb-rounded-full">
                @forelse($conversations as $conversation)
                    @php
                        $last = $conversation->latestMessage;
                        $isSelected = $conversation->id === $active?->id;
                    @endphp

                    <button
                        type="button"
                        wire:key="vada-conversation-{{ $conversation->id }}"
                        @click="open('{{ $conversation->id }}')"
                        @if($isSelected) aria-current="true" @endif
                        class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-purple-500"
                        :class="selected === '{{ $conversation->id }}'
                            ? 'bg-purple-50 dark:bg-white/10'
                            : 'hover:bg-gray-50 dark:hover:bg-white/5'">

                        <span class="grid size-9 shrink-0 place-items-center rounded-full text-xs font-semibold"
                              :class="selected === '{{ $conversation->id }}'
                                ? 'bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-200'
                                : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'">
                            {{ mb_substr((string) \Bale\Vada\Support\PhoneIndex::normalize($conversation->contact_phone), -2) }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline gap-2">
                                <span class="min-w-0 flex-1 truncate text-[13px] font-medium text-gray-900 dark:text-gray-100">
                                    {{ \Bale\Vada\Support\PhoneIndex::display($conversation->contact_phone) }}
                                </span>
                                <span class="shrink-0 text-[11px] {{ $conversation->unread_count > 0 ? 'font-semibold text-purple-700 dark:text-purple-300' : 'text-gray-500 dark:text-gray-400' }}">
                                    @if($conversation->last_message_at?->isToday())
                                        {{ $conversation->last_message_at->format('H:i') }}
                                    @else
                                        {{ $conversation->last_message_at?->format('d/m/Y') }}
                                    @endif
                                </span>
                            </span>

                            <span class="mt-0.5 flex items-center gap-2">
                                <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                    @if($last)
                                        @php
                                            $preview = match (true) {
                                                $last->status === \Bale\Vada\Models\VadaMessage::STATUS_DELETED => __('Pesan ini telah dihapus.'),
                                                trim((string) $last->body) !== '' => $last->body,
                                                default => $last->mediaLabel(),
                                            };
                                        @endphp
                                        {{ $last->isOutgoing() ? __('Anda: ') : '' }}{{ \Illuminate\Support\Str::limit((string) $preview, 60) }}
                                    @endif
                                </span>

                                @if($conversation->unread_count > 0)
                                    <span class="inline-flex h-4.5 min-w-4.5 shrink-0 items-center justify-center rounded-full bg-purple-600 px-1.5 text-[10px] font-bold text-white tabular-nums">
                                        {{ $conversation->unread_count }}
                                        <span class="sr-only">{{ __('pesan belum dibaca') }}</span>
                                    </span>
                                @endif
                            </span>
                        </span>
                    </button>
                @empty
                    <p class="px-6 py-10 text-center text-[13px] text-gray-500 dark:text-gray-400">
                        {{ $search !== '' ? __('Nomor tidak ditemukan.') : __('Belum ada percakapan.') }}
                    </p>
                @endforelse
            </div>
        </aside>

        {{-- Percakapan --}}
        <section class="min-h-0 min-w-0 flex-1 flex-col lg:flex" :class="mobileOpen ? 'flex' : 'max-lg:hidden'">
            @if(! $active)
                <div class="flex flex-1 flex-col items-center justify-center px-8 text-center">
                    <x-lucide-message-square class="size-8 text-gray-300 dark:text-gray-600" />
                    <p class="mt-3 text-[13px] font-medium text-gray-500 dark:text-gray-400">{{ __('Pilih percakapan') }}</p>
                </div>
            @else
                <header class="flex h-14 shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-3 dark:border-white/10 dark:bg-gray-900 sm:px-4">
                    <button type="button" @click="back()"
                            class="-ml-1 flex size-9 items-center justify-center rounded-lg text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white lg:hidden"
                            aria-label="{{ __('Kembali ke daftar percakapan') }}">
                        <x-lucide-arrow-left class="size-5" />
                    </button>

                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        {{ mb_substr((string) \Bale\Vada\Support\PhoneIndex::normalize($active->contact_phone), -2) }}
                    </span>

                    <h2 class="min-w-0 flex-1 truncate text-[13px] font-semibold text-gray-900 dark:text-white">
                        {{ \Bale\Vada\Support\PhoneIndex::display($active->contact_phone) }}
                    </h2>

                    @if($contexts !== [])
                        <button type="button"
                                x-ref="panelTrigger"
                                @click="openPanel()"
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white xl:hidden"
                                :aria-expanded="panelOpen ? 'true' : 'false'"
                                aria-controls="vada-applicant-panel"
                                aria-label="{{ __('Data pemohon') }}">
                            <x-lucide-panel-right class="size-5" />
                        </button>
                    @endif
                </header>

                <div wire:loading wire:target="selectConversation" class="min-h-0 flex-1 space-y-2 bg-gray-50 p-4 dark:bg-gray-950" aria-hidden="true">
                    @for($i = 0; $i < 6; $i++)
                        <div class="flex {{ $i % 2 === 0 ? 'justify-start' : 'justify-end' }}">
                            <div class="h-9 animate-pulse rounded-2xl bg-gray-200 dark:bg-white/10 {{ $i % 2 === 0 ? 'w-2/3' : 'w-1/2' }}"></div>
                        </div>
                    @endfor
                </div>

                {{-- Klik kanan pada bubble membuka context menu Alpine di posisi
                     kursor. Satu menu dipakai bersama; pesan yang dituju disimpan
                     di state Alpine. --}}
                <div x-ref="thread"
                     wire:loading.remove
                     wire:target="selectConversation"
                     @scroll.passive="menu.open = false; trackScroll()"
                     @contextmenu.prevent="onContextMenu($event)"
                     class="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain scroll-auto bg-gradient-to-br from-gray-50 via-white to-purple-50/60 px-3 py-4 scrollbar-thin scrollbar-thumb-gray-400 dark:scrollbar-thumb-gray-600 scrollbar-track-gray-200 dark:scrollbar-track-gray-800 scrollbar-thumb-rounded-full dark:from-gray-950 dark:via-gray-950 dark:to-gray-900 sm:px-6">
                    @if($hasOlder ?? false)
                        <div class="flex justify-center pb-2">
                            <button type="button"
                                    x-on:click="loadOlder()"
                                    wire:loading.attr="disabled"
                                    wire:target="loadOlder"
                                    class="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/10 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                <span wire:loading.remove wire:target="loadOlder">{{ __('Tampilkan chat lama') }}</span>
                                <span wire:loading wire:target="loadOlder">{{ __('Memuat chat lama…') }}</span>
                            </button>
                        </div>
                    @endif

                    @php $lastDate = null; @endphp

                    @foreach($messages as $message)
                        @php
                            $date = $message->created_at?->toDateString();
                            $out = $message->isOutgoing();
                            $snippet = match (true) {
                                $message->status === \Bale\Vada\Models\VadaMessage::STATUS_DELETED => __('Pesan ini telah dihapus.'),
                                trim((string) $message->body) !== '' => $message->body,
                                default => $message->mediaLabel(),
                            };
                        @endphp

                        @if($date !== null && $date !== $lastDate)
                            @php $lastDate = $date; @endphp
                            <div class="flex justify-center py-1.5">
                                <span class="rounded-md bg-gray-200/70 px-2 py-0.5 text-[10px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                    @if($message->created_at?->isToday())
                                        {{ __('Hari ini') }}
                                    @elseif($message->created_at?->isYesterday())
                                        {{ __('Kemarin') }}
                                    @else
                                        {{ $message->created_at?->translatedFormat('d M Y') }}
                                    @endif
                                </span>
                            </div>
                        @endif

                        <div wire:key="vada-message-{{ $message->id }}"
                             data-vada-message="{{ $message->id }}"
                             data-revocable="{{ $message->canRevoke() ? '1' : '0' }}"
                             data-deleted="{{ $message->status === \Bale\Vada\Models\VadaMessage::STATUS_DELETED ? '1' : '0' }}"
                             data-author="{{ $message->isOutgoing() ? __('Anda') : __('Kontak') }}"
                             data-snippet="{{ \Illuminate\Support\Str::limit((string) $snippet, 120) }}"
                             @if($out) data-outgoing @endif
                             @class(['group', 'flex', 'justify-end' => $out, 'justify-start' => ! $out])>
                            <div x-bind:data-menu-open="menu.open && menu.id === '{{ $message->id }}' ? '1' : '0'"
                                 @class([
                                'max-w-[85%] rounded-2xl px-3.5 py-2 text-[13px] leading-relaxed transition duration-200 hover:ring-2 sm:max-w-[70%]',
                                'rounded-br-md bg-purple-500/15 text-gray-900 ring-1 ring-purple-500/20 backdrop-blur-md hover:ring-purple-400/50 data-[menu-open=1]:ring-2 data-[menu-open=1]:ring-purple-400/60 dark:bg-purple-400/10 dark:text-purple-50 dark:ring-purple-300/15' => $out,
                                'rounded-bl-md bg-white/70 text-gray-900 ring-1 ring-gray-200/70 backdrop-blur-md hover:ring-gray-400/60 data-[menu-open=1]:ring-2 data-[menu-open=1]:ring-purple-400/60 dark:bg-white/[0.06] dark:text-gray-100 dark:ring-white/10' => ! $out,
                            ])>
                                @if($message->replyTo)
                                    @php
                                        $quoted = $message->replyTo;
                                        $quotedText = $quoted->status === \Bale\Vada\Models\VadaMessage::STATUS_DELETED
                                            ? __('Pesan ini telah dihapus.')
                                            : (trim((string) $quoted->body) !== '' ? $quoted->body : $quoted->mediaLabel());
                                    @endphp

                                    <div @class([
                                        'mb-1.5 rounded-lg border-l-2 px-2 py-1 text-left',
                                        'border-purple-500/60 bg-white/30 dark:border-purple-300/50 dark:bg-white/5' => $out,
                                        'border-gray-400/60 bg-gray-100/70 dark:border-gray-500 dark:bg-gray-900/30' => ! $out,
                                    ])>
                                        <p @class([
                                            'text-[10px] font-semibold',
                                            'text-purple-700 dark:text-purple-200' => $out,
                                            'text-gray-600 dark:text-gray-300' => ! $out,
                                        ])>
                                            {{ $quoted->isOutgoing() ? __('Anda') : __('Kontak') }}
                                        </p>
                                        <p @class([
                                            'line-clamp-2 break-words text-[11px]',
                                            'text-gray-700 dark:text-purple-100/80' => $out,
                                            'text-gray-600 dark:text-gray-400' => ! $out,
                                        ])>
                                            {{ \Illuminate\Support\Str::limit((string) $quotedText, 120) }}
                                        </p>
                                    </div>
                                @endif

                                @if($message->status === \Bale\Vada\Models\VadaMessage::STATUS_DELETED)
                                    <p class="flex items-center gap-1.5 text-xs italic text-gray-500 dark:text-gray-400">
                                        <x-lucide-ban class="size-3.5" aria-hidden="true" />
                                        {{ __('Pesan ini telah dihapus.') }}
                                    </p>
                                @endif

                                @if($message->hasMedia())
                                    @php
                                        $mediaUrl = $message->media_status === \Bale\Vada\Models\VadaMessage::MEDIA_READY
                                            ? \Bale\Vada\Support\Media::url($message)
                                            : null;
                                    @endphp

                                    @if($mediaUrl !== null && $message->isImage())
                                        <a href="{{ $mediaUrl }}" target="_blank" rel="noopener"
                                           x-data="{ failed: false }"
                                           class="mb-1.5 block overflow-hidden rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500">
                                            {{-- Gambar tidak boleh disembunyikan dengan `display:none`: browser melewati gambar lazy yang tidak terlihat. --}}
                                            <img src="{{ $mediaUrl }}" alt="{{ $message->media_name ?: __('Gambar') }}"
                                                 loading="lazy" decoding="async"
                                                 x-show="! failed"
                                                 x-on:load="if (atBottom) scrollToBottom()"
                                                 x-on:error="failed = true"
                                                 class="max-h-64 w-auto max-w-full object-contain">

                                            <p x-show="failed" x-cloak
                                               class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-2 text-xs text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                                                <x-lucide-triangle-alert class="size-3.5" />
                                                {{ __('Gambar gagal dimuat.') }}
                                            </p>
                                        </a>
                                    @elseif($mediaUrl !== null && $message->media_type === 'audio')
                                        <audio controls preload="none" src="{{ $mediaUrl }}"
                                               x-on:loadedmetadata="if (atBottom) scrollToBottom()"
                                               class="mb-1 w-60 max-w-full"></audio>
                                    @elseif($mediaUrl !== null)
                                        <a href="{{ $mediaUrl }}" target="_blank" rel="noopener"
                                           @class([
                                               'mb-1.5 flex items-center gap-2 rounded-lg px-2.5 py-2 transition-colors',
                                               'bg-white/40 hover:bg-white/70 dark:bg-white/10 dark:hover:bg-white/15' => $out,
                                               'bg-gray-50 hover:bg-gray-100 dark:bg-gray-900/40 dark:hover:bg-gray-900' => ! $out,
                                           ])>
                                            <x-lucide-file-text @class(['size-4 shrink-0', 'text-gray-500 dark:text-purple-200/80' => $out, 'text-gray-500 dark:text-gray-400' => ! $out]) />
                                            <span class="min-w-0 flex-1">
                                                <span @class(['block truncate text-xs font-medium', 'text-gray-900 dark:text-white' => $out, 'text-gray-800 dark:text-gray-100' => ! $out])>
                                                    {{ $message->media_name ?: __('Dokumen') }}
                                                </span>
                                                @if($message->media_size)
                                                    <span @class(['block text-[10px]', 'text-gray-500 dark:text-purple-200/70' => $out, 'text-gray-500 dark:text-gray-400' => ! $out])>
                                                        {{ \Illuminate\Support\Number::fileSize($message->media_size) }}
                                                    </span>
                                                @endif
                                            </span>
                                            <x-lucide-download @class(['size-4 shrink-0', 'text-gray-500 dark:text-purple-200/80' => $out, 'text-gray-500 dark:text-gray-400' => ! $out]) />
                                        </a>
                                    @else
                                        <p @class([
                                            'mb-1 flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-xs',
                                            'bg-white/40 text-gray-600 dark:bg-white/10 dark:text-purple-200/80' => $out,
                                            'bg-gray-100 text-gray-600 dark:bg-gray-900/40 dark:text-gray-300' => ! $out,
                                        ])>
                                            @if($message->media_status === \Bale\Vada\Models\VadaMessage::MEDIA_PENDING)
                                                <x-lucide-loader-circle class="size-3.5 animate-spin" />
                                                {{ __('Mengunduh media') }}
                                            @elseif($message->media_status === \Bale\Vada\Models\VadaMessage::MEDIA_EXPIRED)
                                                <x-lucide-clock class="size-3.5" />
                                                {{ __('Media kedaluwarsa.') }}
                                            @elseif(in_array($message->media_type, ['video', 'sticker'], true))
                                                <x-lucide-triangle-alert class="size-3.5" />
                                                {{ __('Media belum didukung.') }}
                                            @else
                                                <x-lucide-triangle-alert class="size-3.5" />
                                                {{ __('Media gagal diunduh.') }}
                                            @endif
                                        </p>
                                    @endif
                                @endif

                                @if(trim((string) $message->body) !== '')
                                    <p class="whitespace-pre-line break-words">{{ $message->body }}</p>
                                @endif

                                <div @class([
                                    'mt-0.5 flex items-center justify-end gap-1 text-[10px] tabular-nums',
                                    'text-gray-600 dark:text-purple-200/70' => $out,
                                    'text-gray-500 dark:text-gray-400' => ! $out,
                                ])>
                                    <time datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->format('H:i') }}</time>

                                    @if($out && $message->status !== \Bale\Vada\Models\VadaMessage::STATUS_DELETED)
                                        @if($message->status === \Bale\Vada\Models\VadaMessage::STATUS_QUEUED)
                                            <x-lucide-clock class="size-3" aria-hidden="true" />
                                            <span class="sr-only">{{ __('Masih di antrean') }}</span>
                                        @elseif($message->status === \Bale\Vada\Models\VadaMessage::STATUS_FAILED)
                                            <x-lucide-circle-alert class="size-3 text-rose-600 dark:text-rose-300" aria-hidden="true" />
                                            <span class="sr-only">{{ $message->error ?: __('Gagal terkirim') }}</span>
                                        @elseif($message->status === \Bale\Vada\Models\VadaMessage::STATUS_READ)
                                            <x-lucide-check-check class="size-3 text-sky-600 dark:text-sky-300" aria-hidden="true" />
                                            <span class="sr-only">{{ __('Dibaca') }}</span>
                                        @elseif($message->status === \Bale\Vada\Models\VadaMessage::STATUS_DELIVERED)
                                            <x-lucide-check-check class="size-3" aria-hidden="true" />
                                            <span class="sr-only">{{ __('Sampai') }}</span>
                                        @else
                                            <x-lucide-check class="size-3" aria-hidden="true" />
                                            <span class="sr-only">{{ __('Terkirim') }}</span>
                                        @endif
                                    @endif
                                </div>
                            </div>

                            {{-- Tombol balas muncul saat baris disorot; di layar
                                 sentuh dipakai item "Balas" pada context menu. --}}
                            <button type="button"
                                    x-on:click="startReply($el.closest('[data-vada-message]'))"
                                    @class([
                                        'mx-1 hidden shrink-0 self-center rounded-full bg-white p-1.5 text-gray-500 opacity-0 shadow-sm ring-1 ring-gray-200 transition-opacity duration-150 hover:text-purple-600 focus-visible:pointer-events-auto focus-visible:opacity-100 focus-visible:outline-none group-hover:pointer-events-auto group-hover:opacity-100 sm:flex dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700 dark:hover:text-purple-300',
                                        'pointer-events-none',
                                        'order-first' => $out,
                                    ])
                                    aria-label="{{ __('Balas') }}"
                                    title="{{ __('Balas') }}">
                                <x-lucide-reply class="size-4" />
                            </button>
                        </div>
                    @endforeach

                    {{-- Bubble sementara: dilepas setelah server mengembalikan
                         baris aslinya. wire:ignore menjaga dari morph wire:poll. --}}
                    <div wire:ignore aria-live="polite" class="space-y-1">
                        <template x-for="p in pending" :key="p.id">
                            <div class="flex justify-end" data-outgoing>
                                <div class="max-w-[85%] rounded-2xl rounded-br-md bg-purple-500/15 px-3.5 py-2 text-[13px] leading-relaxed text-gray-900 ring-1 ring-purple-500/20 backdrop-blur-md sm:max-w-[70%] dark:bg-purple-400/10 dark:text-purple-50 dark:ring-purple-300/15">
                                    <template x-if="p.file">
                                        <p class="mb-1.5 flex items-center gap-2 rounded-lg bg-white/40 px-2.5 py-2 text-xs text-gray-600 dark:bg-white/10 dark:text-purple-200/80">
                                            <x-lucide-file class="size-4 shrink-0" aria-hidden="true" />
                                            <span class="min-w-0 truncate" x-text="p.file"></span>
                                        </p>
                                    </template>

                                    <p x-show="p.body !== ''" class="whitespace-pre-line break-words" x-text="p.body"></p>

                                    <div class="mt-0.5 flex items-center justify-end gap-1 text-[10px] text-gray-600 tabular-nums dark:text-purple-200/70">
                                        <span x-text="p.at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })"></span>
                                        <x-lucide-clock class="size-3" aria-hidden="true" />
                                        <span class="sr-only">{{ __('Masih di antrean') }}</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Konteks balasan aktif; seluruhnya state Alpine, tidak
                     menunggu round-trip server. --}}
                <template x-if="replyTo">
                    <div class="flex shrink-0 items-center gap-2 border-t border-gray-200 bg-gray-50 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                        <div class="min-w-0 flex-1 border-l-2 border-purple-500 pl-2.5">
                            <p class="text-[11px] font-semibold text-purple-700 dark:text-purple-300">
                                {{ __('Membalas') }} <span x-text="replyTo.author"></span>
                            </p>
                            <p class="truncate text-xs text-gray-600 dark:text-gray-300" x-text="replyTo.snippet"></p>
                        </div>

                        <button type="button"
                                x-on:click="replyTo = null"
                                class="shrink-0 rounded-lg p-1.5 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white"
                                aria-label="{{ __('Batal balas') }}">
                            <x-lucide-x class="size-4" />
                        </button>
                    </div>
                </template>

                {{-- Composer --}}
                <form x-on:submit.prevent="send($el)"
                      class="flex shrink-0 items-end gap-2 border-t border-gray-200 bg-white px-2 py-2 dark:border-white/10 dark:bg-gray-900 sm:px-3">
                    <label for="vada-attachment"
                           class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 focus-within:ring-2 focus-within:ring-purple-500 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white"
                           title="{{ __('Lampirkan berkas') }}">
                        <x-lucide-paperclip class="size-5" />
                        <span class="sr-only">{{ __('Lampirkan berkas') }}</span>
                        <input id="vada-attachment"
                               type="file"
                               wire:model="attachment"
                               accept=".jpg,.jpeg,.png,.webp,.ogg,.mp3,.pdf,.doc,.docx,.xls,.xlsx"
                               class="sr-only">
                    </label>

                    <div class="min-w-0 flex-1">
                        @if(! empty($attachment))
                            <div class="mb-1.5 flex items-center gap-2 rounded-lg bg-gray-100 px-2.5 py-1.5 dark:bg-white/10">
                                <x-lucide-file class="size-4 shrink-0 text-gray-500 dark:text-gray-400" />
                                <span class="min-w-0 flex-1 truncate text-xs text-gray-700 dark:text-gray-200">
                                    {{ $attachment->getClientOriginalName() }}
                                </span>
                                <button type="button" wire:click="$set('attachment', null)"
                                        class="shrink-0 text-gray-500 transition-colors hover:text-rose-600 dark:text-gray-400 dark:hover:text-rose-400"
                                        aria-label="{{ __('Hapus lampiran') }}">
                                    <x-lucide-x class="size-4" />
                                </button>
                            </div>
                        @endif

                        <div wire:loading wire:target="attachment" class="mb-1.5">
                            <div class="h-1 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                <div class="h-full w-1/2 animate-pulse rounded-full bg-purple-600"></div>
                            </div>
                        </div>

                        <label for="vada-reply" class="sr-only">{{ __('Ketik pesan') }}</label>
                        <textarea
                            id="vada-reply"
                            wire:model="reply"
                            rows="1"
                            x-on:input="grow($el)"
                            x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); if ($el.value.trim() !== '' || $wire.attachment) { $el.form.requestSubmit(); } }"
                            placeholder="{{ __('Ketik pesan') }}"
                            class="block max-h-36 w-full resize-none rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-[13px] text-gray-900 placeholder-gray-500 transition-colors scrollbar-thin scrollbar-thumb-gray-400 scrollbar-track-transparent scrollbar-thumb-rounded-full focus:border-purple-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-purple-500/25 dark:scrollbar-thumb-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-100 dark:placeholder-gray-400 dark:focus:bg-gray-900"></textarea>

                        @error('reply')
                            <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                        @enderror

                        @error('attachment')
                            <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="attachment"
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-purple-600 text-white transition-colors hover:bg-purple-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-offset-gray-900"
                            aria-label="{{ __('Kirim pesan') }}">
                        <x-lucide-send class="size-4.5" />
                    </button>
                </form>
            @endif
        </section>

        {{-- Panel pemohon --}}
        @if($active && $contexts !== [])
            <aside class="relative hidden shrink-0 border-l border-gray-200 dark:border-white/10 xl:block"
                   style="width: var(--vada-panel-w, 24rem)"
                   aria-label="{{ __('Data pemohon') }}">
                {{-- Pegangan lebar. Bisa digeser dengan tetikus dan diatur
                     dengan panah kiri/kanan saat fokus keyboard. --}}
                <div role="separator"
                     aria-orientation="vertical"
                     tabindex="0"
                     title="{{ __('Geser untuk mengubah lebar panel') }}"
                     aria-label="{{ __('Ubah lebar panel pemohon') }}"
                     aria-valuemin="320"
                     aria-valuemax="640"
                     :aria-valuenow="panelWidth"
                     x-on:pointerdown.prevent="resizePanel($event)"
                     x-on:pointermove="dragPanel($event)"
                     x-on:pointerup="endPanelResize($event)"
                     x-on:pointercancel="endPanelResize($event)"
                     x-on:keydown.arrow-left.prevent="nudgePanel(16)"
                     x-on:keydown.arrow-right.prevent="nudgePanel(-16)"
                     x-on:keydown.home.prevent="setPanelWidth(320, true)"
                     x-on:keydown.end.prevent="setPanelWidth(640, true)"
                     class="absolute inset-y-0 -left-1 z-10 w-2 cursor-col-resize touch-none select-none bg-transparent after:absolute after:inset-y-0 after:left-1/2 after:w-0.5 after:-translate-x-1/2 after:bg-gray-200 after:transition-colors hover:after:bg-purple-400 focus:outline-none focus-visible:after:bg-purple-500 dark:after:bg-white/15"></div>

                @include('vada::livewire.shared-components.chat.section.contact-contexts', ['contexts' => $contexts, 'showClose' => false])
            </aside>

            <div x-show="panelOpen"
                 x-cloak
                 @keydown.escape.window="if (panelOpen) closePanel()"
                 class="fixed inset-0 z-50 xl:hidden"
                 role="dialog"
                 aria-modal="true"
                 aria-label="{{ __('Data pemohon') }}">
                <div class="absolute inset-0 bg-gray-950/50" @click="closePanel()" aria-hidden="true"></div>

                <aside id="vada-applicant-panel"
                       x-ref="panel"
                       tabindex="-1"
                       class="absolute inset-y-0 right-0 flex w-full max-w-sm flex-col bg-white shadow-xl focus:outline-none dark:bg-gray-900">
                    @include('vada::livewire.shared-components.chat.section.contact-contexts', ['contexts' => $contexts, 'showClose' => true])
                </aside>
            </div>
        @endif
    @endif

    {{-- Menu konteks Alpine. `x-teleport` hanya bekerja pada <template>, dan
         menu dipindah ke <body> supaya `fixed` tidak dihitung dari ancestor
         ber-transform (yang membuatnya meleset ke pojok kiri atas). --}}
    <template x-teleport="body">
    <div x-show="menu.open"
         x-cloak
         wire:ignore
         x-transition.opacity.duration.150ms
         x-on:click.outside="menu.open = false"
         x-on:contextmenu.prevent.stop
         :style="{ left: menu.x + 'px', top: menu.y + 'px' }"
         class="fixed z-[80] min-w-48 rounded-xl border border-gray-200 bg-white p-1 shadow-xl dark:border-gray-700 dark:bg-gray-800"
         role="menu">
        <button type="button" role="menuitem"
                x-show="! menu.deleted"
                x-on:click="replyFromMenu()"
                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-[13px] text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10">
            <x-lucide-reply class="size-4" aria-hidden="true" />
            {{ __('Balas') }}
        </button>

        <button type="button" role="menuitem"
                x-show="! menu.deleted"
                x-on:click="menu.open = false; $wire.deleteMessageForMe(menu.id)"
                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-[13px] text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10">
            <x-lucide-trash-2 class="size-4" aria-hidden="true" />
            {{ __('Hapus untuk saya') }}
        </button>

        <button type="button" role="menuitem"
                x-show="menu.revocable"
                x-on:click="menu.open = false; $wire.deleteMessageForEveryone(menu.id)"
                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-[13px] text-rose-600 transition-colors hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-900/20">
            <x-lucide-trash-2 class="size-4" aria-hidden="true" />
            {{ __('Hapus untuk semua') }}
        </button>
    </div>
    </template>
</div>
