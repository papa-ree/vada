{{--
    Isi panel pemohon. Dipakai dua kali: kolom tetap di layar lebar dan
    drawer di bawah 1280px. Semua data datang dari provider tenant lewat
    ContactRegistry; view ini tidak tahu domain apa pun.
--}}
@php
    $badge = fn (string $tone) => match ($tone) {
        'teal' => 'bg-teal-50 text-teal-700 border-teal-100 dark:bg-teal-900/20 dark:text-teal-300 dark:border-teal-800/40',
        'amber' => 'bg-amber-50 text-amber-700 border-amber-100 dark:bg-amber-900/20 dark:text-amber-400 dark:border-amber-800/40',
        'rose' => 'bg-rose-50 text-rose-700 border-rose-100 dark:bg-rose-900/20 dark:text-rose-400 dark:border-rose-800/40',
        default => 'bg-gray-100 text-gray-600 border-gray-200 dark:bg-white/10 dark:text-gray-300 dark:border-white/10',
    };
    $sections = array_slice($contexts, 0, 2);
@endphp

<div class="flex h-full flex-col">
    <div class="flex h-14 shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4 dark:border-white/10">
        <h2 class="text-[13px] font-semibold text-gray-900 dark:text-white">{{ __('Pemohon') }}</h2>

        @if($showClose ?? false)
            <button type="button"
                    @click="closePanel()"
                    class="-mr-1 flex size-9 shrink-0 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white"
                    aria-label="{{ __('Tutup panel pemohon') }}">
                <x-lucide-x class="size-4" />
            </button>
        @endif
    </div>

    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-3 scrollbar-thin scrollbar-thumb-gray-400 dark:scrollbar-thumb-gray-600 scrollbar-track-gray-200 dark:scrollbar-track-gray-800 scrollbar-thumb-rounded-full">
        @foreach($sections as $context)
            <section class="rounded-lg border border-gray-200 p-3.5 dark:border-white/10">
                <p class="text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                    {{ $context->source }}
                </p>

                @if($context->matched())
                    <div class="mt-3 flex items-start gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-purple-50 text-xs font-semibold text-purple-700 dark:bg-purple-500/20 dark:text-purple-200">
                            {{ mb_strtoupper(mb_substr((string) $context->name, 0, 2)) }}
                        </span>

                        <div class="min-w-0">
                            <p class="truncate text-[13px] font-semibold text-gray-900 dark:text-white">{{ $context->name }}</p>

                            @if($context->caption)
                                <span class="mt-1 inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-medium {{ $badge($context->captionTone) }}">
                                    {{ $context->caption }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @if($context->fields !== [])
                        <dl class="mt-3 space-y-1.5 text-xs">
                            @foreach($context->fields as $label => $value)
                                <div class="flex gap-2">
                                    <dt class="w-16 shrink-0 text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                    <dd class="min-w-0 flex-1 break-words text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if($context->actions !== [])
                        <div class="mt-3 space-y-2">
                            @foreach($context->actions as $action)
                                <x-core::button link href="{{ $action['url'] }}" label="{{ $action['label'] }}" class="w-full!" />
                            @endforeach
                        </div>
                    @endif

                    {{-- Panel bebas milik package tenant (mis. form layanan). --}}
                    @if($context->panelView && view()->exists($context->panelView))
                        <div class="mt-3">
                            @include($context->panelView, array_merge($context->panelData, [
                                'variant' => ($showClose ?? false) ? 'drawer' : 'column',
                            ]))
                        </div>
                    @endif

                    <div class="mt-3 border-t border-gray-100 pt-3 dark:border-white/10">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ __('Riwayat pengajuan') }}</h3>

                            @if($context->historyUrl && $context->history !== [])
                                <a href="{{ $context->historyUrl }}"
                                   wire:navigate.hover
                                   class="inline-flex items-center gap-0.5 rounded text-xs font-medium text-purple-600 transition-colors hover:text-purple-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 dark:text-purple-400 dark:hover:text-purple-300">
                                    {{ __('Lihat semua') }}
                                    <x-lucide-chevron-right class="size-3" aria-hidden="true" />
                                </a>
                            @endif
                        </div>

                        @if($context->history === [])
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Belum ada pengajuan.') }}
                            </p>
                        @else
                            <ul class="mt-2 space-y-1.5">
                                @foreach($context->history as $item)
                                    <li>
                                        @if($item->url)
                                            <a href="{{ $item->url }}"
                                               wire:navigate.hover
                                               class="block rounded-md border border-gray-200 px-2.5 py-2 transition-colors hover:border-purple-300 hover:bg-purple-50/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 dark:border-white/10 dark:hover:border-purple-700 dark:hover:bg-purple-950/20">
                                        @else
                                            <div class="block rounded-md border border-gray-200 px-2.5 py-2 dark:border-white/10">
                                        @endif

                                            <div class="flex items-start justify-between gap-2">
                                                <span class="line-clamp-2 min-w-0 flex-1 text-sm font-medium leading-snug break-words text-gray-900 dark:text-gray-100">{{ $item->title }}</span>

                                                @if($item->status)
                                                    <span class="inline-flex shrink-0 items-center rounded-md border px-1.5 py-0.5 text-[10px] font-medium {{ $badge($item->tone) }}">
                                                        {{ $item->status }}
                                                    </span>
                                                @endif
                                            </div>

                                            @if($item->meta)
                                                <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $item->meta }}</p>
                                            @endif

                                        @if($item->url)
                                            </a>
                                        @else
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @else
                    <div class="mt-3 flex flex-col items-center rounded-lg bg-gray-50 px-3 py-5 text-center dark:bg-white/5">
                        <x-lucide-user-round-search class="size-5 text-gray-400 dark:text-gray-500" />

                        <p class="mt-2 text-xs font-medium text-gray-700 dark:text-gray-300">
                            {{ $context->emptyMessage ?? __('Data pemohon tidak tersedia.') }}
                        </p>

                        @if($context->fallbackAction)
                            <x-core::secondary-button link href="{{ $context->fallbackAction['url'] }}" label="{{ $context->fallbackAction['label'] }}" class="mt-3 w-full!" />
                        @endif
                    </div>
                @endif
            </section>
        @endforeach
    </div>
</div>
