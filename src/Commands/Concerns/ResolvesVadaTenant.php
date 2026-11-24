<?php

namespace Bale\Vada\Commands\Concerns;

use Bale\Cms\Models\BaleList;

/**
 * Pemilihan tenant yang sama untuk `vada:assign` dan `vada:unassign`.
 */
trait ResolvesVadaTenant
{
    protected function resolveTenant(): ?BaleList
    {
        $argument = $this->argument('tenant');

        if (filled($argument)) {
            $tenant = BaleList::where('slug', $argument)->orWhere('id', $argument)->first();

            if ($tenant === null) {
                $this->error('Tenant tidak ditemukan.');
            }

            return $tenant;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Slug tenant wajib diisi saat dijalankan non-interaktif.');

            return null;
        }

        $tenants = BaleList::orderBy('name')->get();

        if ($tenants->isEmpty()) {
            $this->error('Belum ada tenant di bale_lists.');

            return null;
        }

        $labels = $tenants
            ->map(fn (BaleList $tenant) => $tenant->name.' ('.$tenant->slug.')'.($tenant->is_active ? '' : ' — nonaktif'))
            ->values()
            ->all();

        $index = $this->pickIndex('Pilih tenant', $labels);

        if ($index === null) {
            $this->error('Pilihan tenant tidak valid.');

            return null;
        }

        return $tenants->values()[$index];
    }

    /**
     * Daftar bernomor seperti `vada:install`: `choice()` mengembalikan labelnya,
     * dan indeks angka saat default yang dipakai.
     *
     * @param  array<int, string>  $labels
     */
    protected function pickIndex(string $question, array $labels): ?int
    {
        $picked = $this->choice($question, $labels, 0);

        if (is_numeric($picked) && isset($labels[(int) $picked])) {
            return (int) $picked;
        }

        $index = array_search($picked, $labels, true);

        return $index === false ? null : $index;
    }
}
