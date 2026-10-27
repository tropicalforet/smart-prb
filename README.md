# SMART PRB

Sistem pemantauan kepatuhan pengambilan obat pasien PRB (Program Rujuk Balik) BPJS Kesehatan.
Laravel 12 + Inertia/React, database SQLite.

## Instalasi di komputer baru

Kebutuhan: PHP 8.2+, Composer, Node.js 20+.

```bash
git clone <url-repo> smart-prb
cd smart-prb
composer run setup
```

`composer run setup` melakukan semuanya: install dependensi, membuat `.env` dari `.env.example`,
membuat `APP_KEY`, membuat database SQLite beserta tabelnya, **membuat akun admin**, lalu build aset frontend.

### Akun admin

Akun admin dibuat oleh `AdminUserSeeder` dari isian `.env`:

| Variabel | Default |
|---|---|
| `ADMIN_EMAIL` | `admin@prbkesehatan.id` |
| `ADMIN_PASSWORD` | *(kosong → dibuat acak)* |

Jika `ADMIN_PASSWORD` kosong, password acak **ditampilkan sekali** di terminal saat setup — catat, lalu ganti
lewat menu **Akun → Ganti Password** setelah login.

Lupa mencatat, atau instalasi lama yang belum punya akun? Isi `ADMIN_PASSWORD` di `.env`, lalu:

```bash
php artisan db:seed --class=AdminUserSeeder
```

Seeder ini tidak pernah mengubah password akun yang sudah ada. Untuk mengganti password akun yang sudah ada
tanpa login:

```bash
php artisan tinker --execute="App\Models\User::where('email','admin@prbkesehatan.id')->first()->update(['password' => Hash::make('PasswordBaru123')]);"
```

### Menjalankan

```bash
composer run dev
```

Buka `http://localhost:8000`.

## Data pasien

Database (`database/database.sqlite`) **tidak disimpan di Git** karena berisi data pasien penelitian.
Setiap instalasi baru dimulai kosong; data diisi lewat **Data Pasien → Import** (file Excel dari apotek).
Jangan membagikan file database lewat repositori publik.

Untuk data contoh (dummy) di lingkungan lokal: `php artisan db:seed` (diblokir di production).

## Tes

```bash
php artisan test
```

Selalu jalankan tes dengan konfigurasi `phpunit.xml` (database in-memory). Menjalankan tes tanpa konfigurasi
tersebut dapat menghapus database asli.
