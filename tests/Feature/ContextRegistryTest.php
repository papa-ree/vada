<?php

use Bale\Vada\Contracts\ContactContextProvider;
use Bale\Vada\Services\ContextRegistry;
use Bale\Vada\Support\ContactContext;

function vadaContextProvider(string $source, array $names, bool $throws = false): ContactContextProvider
{
    return new class($source, $names, $throws) implements ContactContextProvider
    {
        public function __construct(
            private string $source,
            private array $names,
            private bool $throws,
        ) {}

        public function label(): string
        {
            return $this->source;
        }

        public function forPhone(string $phone): array
        {
            if ($this->throws) {
                throw new RuntimeException('provider rusak');
            }

            return array_map(
                fn (string $name) => new ContactContext(source: $this->source, name: $name),
                $this->names,
            );
        }
    };
}

it('mengembalikan konteks dari semua provider sesuai urutan registrasi', function () {
    $registry = new ContextRegistry;

    expect($registry->hasProviders())->toBeFalse();

    $registry->register(vadaContextProvider('Sidulur', ['Ani']));
    $registry->register(vadaContextProvider('PDAM', ['Ani', 'Budi']));

    $contexts = $registry->resolve('628123456789');

    expect($registry->hasProviders())->toBeTrue()
        ->and(array_map(fn (ContactContext $c) => $c->source, $contexts))->toBe(['Sidulur', 'PDAM', 'PDAM'])
        ->and(array_map(fn (ContactContext $c) => $c->name, $contexts))->toBe(['Ani', 'Ani', 'Budi']);
});

it('melewati provider yang gagal tanpa menjatuhkan provider berikutnya', function () {
    $registry = new ContextRegistry;

    $registry->register(vadaContextProvider('Rusak', [], throws: true));
    $registry->register(vadaContextProvider('Sidulur', ['Ani']));

    $contexts = $registry->resolve('628123456789');

    expect($contexts)->toHaveCount(1)
        ->and($contexts[0]->source)->toBe('Sidulur');
});

it('mengembalikan daftar kosong saat nomor tidak dikenali', function () {
    $registry = new ContextRegistry;
    $registry->register(vadaContextProvider('Sidulur', []));

    expect($registry->resolve('628123456789'))->toBe([]);
});
