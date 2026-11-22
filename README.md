# bale/vada

Inbox chat WhatsApp untuk tenant Bale CMS. Admin tenant membaca percakapan,
membalas teks atau media, mengutip pesan, dan menghapus pesan langsung dari
dashboard. Package ini hanya berisi ruang kerja chat; halaman, layout, dan
judulnya milik package pemanggil.

Data percakapan disimpan di database tenant, bukan landlord: satu tenant hanya
melihat nomornya sendiri. Pengiriman dan penerimaan WhatsApp didelegasikan ke
`bale/wara`; Vada tidak tahu detail gateway.

## Kemampuan

| Kemampuan | Keterangan |
|---|---|
| Daftar percakapan | Dikelompokkan per nomor, diurutkan `last_message_at`, pencarian nomor lewat blind index |
| Thread pesan | 200 pesan terakhir, polling ringan, penanda belum dibaca |
| Kirim teks | Antre lewat job, status `queued → sent → delivered → read` |
| Kirim media | Gambar (`jpg/jpeg/jfif/png/webp`), audio (`ogg/mp3`), dokumen (`pdf/doc/docx/xls/xlsx`); video dan stiker belum didukung |
| Balas pesan | Reply WhatsApp asli (`reply_message_id`), termasuk kutipan dari pesan masuk |
| Hapus pesan | Hapus untuk saya, atau tarik untuk semua orang (`revoke`) |
| Pesan masuk | Listener `WaraIncomingMessage` menyimpan teks, media, echo dari HP, dan kutipan |
| Panel konteks | Provider dari package lain bisa menampilkan data kontak di sidebar kanan |
| Media | Salinan milik Bale di disk privat, disajikan lewat URL bertanda tangan untuk gateway |

## Kebutuhan

| Dependency | Alasan |
|---|---|
| PHP `^8.3` | Kebutuhan runtime |
| `bale/cms` | Multi-tenancy, koneksi database tenant, permission |
| `bale/core` | Auth, komponen UI, layout |
| `bale/wara` | Pengiriman dan penerimaan WhatsApp |
| Laravel `^11 \|\| ^12 \|\| ^13` | Kompatibilitas lintas versi |
| Livewire `^4` | Komponen inbox chat |

## Instalasi

Package ini adalah Composer *path repository* di dalam monorepo `bale-dev`,
bukan paket Packagist. Daftarkan dulu di `composer.json` aplikasi:

```json
"repositories": [
    { "type": "path", "url": "./packages/vada" }
],
"require": {
    "bale/vada": "dev-master"
}
```

Lalu pasang:

```bash
composer install
php artisan vada:install
```

`vada:install` mempublish config, men-seed permission `vada.access` dan
`vada.manage`, mempublish migration tenant, lalu menjalankan migrasi. Gunakan
`--tenant=<slug>` untuk langsung memigrasi satu tenant dan `--all` untuk
menjalankan semua langkah tanpa prompt.

Alternatif manual:

```bash
php artisan vada:publish-config
php artisan vada:publish-migrations
php artisan vada:migrate --tenant=<slug>
```

Migrasi tabel tenant dipublikasikan ke `database/migrations/vada/`, bukan
`database/migrations/tenant/`. Jalankan `vada:migrate --tenant=<slug>` untuk
setiap tenant yang memakai Vada.

Setelah package terpasang, hubungkan nomor WhatsApp tenant ke device Wara:

```bash
php artisan vada:assign <slug-tenant> <session-id-device> --label="Layanan Sosial"
```

Satu tenant memakai satu kanal. `--disable` menonaktifkan kanal tanpa
menghapus percakapan.

## Menampilkan Halaman Chat

Vada tidak mendaftarkan route halaman. Package pemanggil membuat halaman tipis
sendiri (contoh: `bale/sidulur`) dengan middleware tenant lalu merender
komponennya:

```blade
<livewire:vada.shared-components.chat.inbox />
```

Route halaman wajib memakai `EnsureBaleSelected` dan `SwitchBaleConnection`
supaya koneksi tenant aktif. Izin `vada.access` diperiksa komponen saat mount,
jadi pengguna tanpa izin ditolak `403` sebelum data tenant dibaca.

## Konfigurasi

`config/vada.php` di-merge di `register()`. Semua nilai punya default yang aman.

| Env | Default | Fungsi |
|---|---|---|
| `VADA_INDEX_KEY` | `app.key` | Kunci HMAC untuk blind index nomor; jangan diganti setelah ada data |
| `VADA_QUEUE_CONNECTION` | default queue | Koneksi antrean pengiriman |
| `VADA_QUEUE_NAME` | `default` | Nama antrean pengiriman |
| `VADA_QUEUE_TRIES` | `3` | Batas percobaan job kirim |
| `VADA_POLL_SECONDS` | `5` | Interval polling halaman chat |
| `VADA_MAX_BODY_LENGTH` | `4096` | Panjang maksimum teks pesan |
| `VADA_MEDIA_DISK` | `vada` | Disk penyimpanan salinan media |
| `VADA_MEDIA_BUCKET` | — | Bucket S3 untuk media; produksi memakai kredensial disk `s3` |
| `VADA_MEDIA_MAX_KB` | `16384` | Ukuran maksimum unggahan |
| `VADA_MEDIA_PRUNE_DAYS` | `100` | Retensi media sebelum dihapus scheduler |

Antrean pengiriman membutuhkan worker (`php artisan queue:work`). Retensi media
dijadwalkan otomatis; cukup jalankan `php artisan schedule:work`.

## Command

| Command | Fungsi |
|---|---|
| `vada:install` | Publish config, seed permission, publish migration, migrate. Opsi `--tenant=`, `--all` |
| `vada:publish-config` | Publish `config/vada.php`. Opsi `--force` |
| `vada:publish-migrations` | Publish migration tenant ke `database/migrations/vada/`. Opsi `--force` |
| `vada:migrate` | Migrasi database tenant. Opsi `--tenant=` |
| `vada:assign` | Tetapkan atau nonaktifkan kanal WhatsApp tenant. Opsi `--label=`, `--disable` |
| `vada:sync-history` | Impor riwayat chat lama dari gateway. Opsi `--tenant=`, `--days=`, `--dry-run` |
| `vada:repair-echoes` | Pindahkan echo lama dari percakapan "nomor sendiri" ke lawan bicara |
| `vada:prune-media` | Hapus media melewati retensi. Opsi `--days=`, `--tenant=` |

## Panel Konteks Kontak

Package lain bisa menampilkan data kontak (mis. warga, pelanggan) di sidebar
kanan dengan mengimplementasikan `Bale\Vada\Contracts\ContactContextProvider`
lalu mendaftarkannya saat boot:

```php
use Bale\Vada\Services\ContextRegistry;

$this->app->make(ContextRegistry::class)->register(MyContactContext::class);
```

Panel hanya muncul bila ada provider yang mengembalikan konteks untuk nomor
percakapan aktif. Semua provider bersifat global, jadi beberapa package bisa
menampilkan panel untuk nomor yang sama.

## Testing

```bash
vendor\bin\pest packages\vada
```

## Credits

- [Papa Ree (Ricky R)](mailto:ricky.romdhoni@gmail.com) — Developer

## License

MIT.
