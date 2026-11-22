<?php

use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Support\ContactContext;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    view()->share('errors', new ViewErrorBag);
});

/**
 * Panel milik package tenant: Vada hanya meng-include view, tanpa tahu isinya.
 */
function vadaPanelView(string $body): string
{
    $directory = sys_get_temp_dir().'/vada-panel-'.uniqid();
    mkdir($directory);

    file_put_contents($directory.'/panel.blade.php', $body);

    app('view')->addLocation($directory);

    return 'panel';
}

function vadaPanelHtml(ContactContext $context): string
{
    $active = new VadaConversation(['contact_phone' => '6285126061182']);
    $active->id = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    return view('vada::livewire.shared-components.chat.inbox', [
        'channel' => (object) ['device_id' => 'dev-a'],
        'account' => ['label' => 'Sidulur', 'phone' => '+62 851-2606-1182'],
        'conversations' => collect(),
        'active' => $active,
        'messages' => collect(),
        'contexts' => [$context],
        'search' => '',
    ])->render();
}

it('merender panelView tenant di kolom dan drawer dengan data panel', function () {
    $view = vadaPanelView('<div data-tenant-panel="{{ $variant }}">{{ $phone }}</div>');

    $html = vadaPanelHtml(new ContactContext(
        source: 'Sidulur',
        name: 'Warga Contoh',
        panelView: $view,
        panelData: ['phone' => '6285126061182'],
    ));

    expect($html)
        ->toContain('data-tenant-panel="column"')
        ->toContain('data-tenant-panel="drawer"')
        ->toContain('6285126061182');
});

it('melewati panelView yang tidak tersedia tanpa error', function () {
    $html = vadaPanelHtml(new ContactContext(
        source: 'Sidulur',
        name: 'Warga Contoh',
        panelView: 'vada::panel-yang-tidak-ada',
    ));

    expect($html)->toContain('Warga Contoh');
});
