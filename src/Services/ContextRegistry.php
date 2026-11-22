<?php

namespace Bale\Vada\Services;

use Bale\Vada\Contracts\ContactContextProvider;
use Bale\Vada\Support\ContactContext;

/**
 * Kumpulan provider konteks dari package tenant.
 *
 * Satu nomor bisa dikenali lebih dari satu package (warga sekaligus pelanggan
 * layanan lain), jadi semua hasil dikembalikan dan view yang membatasi
 * berapa panel yang ditampilkan.
 */
class ContextRegistry
{
    /** @var array<int, ContactContextProvider> */
    protected array $providers = [];

    public function register(ContactContextProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    public function hasProviders(): bool
    {
        return $this->providers !== [];
    }

    /**
     * @return array<int, ContactContext>
     */
    public function resolve(string $phone): array
    {
        $contexts = [];

        foreach ($this->providers as $provider) {
            try {
                foreach ($provider->forPhone($phone) as $context) {
                    $contexts[] = $context;
                }
            } catch (\Throwable $e) {
                // Panel konteks tidak boleh menjatuhkan halaman chat.
                report($e);
            }
        }

        return $contexts;
    }
}
