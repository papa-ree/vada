<?php

use Bale\Sidulur\Support\BlindIndex;
use Bale\Vada\Models\VadaConversation;
use Bale\Vada\Support\PhoneIndex;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['vada.index_key' => 'test-index-key']);

    Schema::dropIfExists('vada_conversations');
    Schema::create('vada_conversations', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->text('contact_phone');
        $table->char('contact_phone_hash', 64)->unique();
        $table->timestamp('last_message_at')->nullable();
        $table->unsignedInteger('unread_count')->default(0);
        $table->string('status')->default('open');
        $table->timestamps();
    });
});

it('normalizes indonesian phone formats to one hash', function () {
    $hashes = collect(['08123456789', '8123456789', '+62 812-3456-789', '628123456789'])
        ->map(fn (string $phone) => PhoneIndex::make($phone))
        ->unique();

    expect($hashes)->toHaveCount(1)
        ->and(PhoneIndex::normalize('08123456789'))->toBe('628123456789')
        ->and(PhoneIndex::make(null))->toBeNull();
});

it('normalizes exactly like sidulur blind index', function () {
    if (! class_exists(BlindIndex::class)) {
        $this->markTestSkipped('Sidulur tidak terpasang di aplikasi ini.');
    }

    // Kalau keduanya berbeda, pencarian pemohon gagal tanpa error apa pun.
    $numbers = [
        '08123456789',
        '8123456789',
        '+62 812-3456-789',
        '628123456789',
        '0851-2606-1182',
        '6285126061182@s.whatsapp.net',
        '  ',
        '',
        null,
        'abc',
    ];

    foreach ($numbers as $number) {
        expect(PhoneIndex::normalize($number))->toBe(BlindIndex::normalize($number));
    }
});

it('reuses one conversation per phone', function () {
    $first = VadaConversation::forPhone('08123456789');
    $second = VadaConversation::forPhone('+628123456789');

    expect($second->id)->toBe($first->id)
        ->and(VadaConversation::count())->toBe(1);
});

it('formats a phone the way WhatsApp Web shows it', function () {
    expect(PhoneIndex::display('6285126061182'))->toBe('+62 851-2606-1182')
        ->and(PhoneIndex::display('0851-2606-1182'))->toBe('+62 851-2606-1182')
        ->and(PhoneIndex::display('6285126061182@s.whatsapp.net'))->toBe('+62 851-2606-1182')
        ->and(PhoneIndex::display(null))->toBe('—')
        ->and(PhoneIndex::display(''))->toBe('—');
});
