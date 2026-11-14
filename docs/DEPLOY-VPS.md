# Hosting SMART PRB di VPS (untuk dicoba dosen)

Server ini berisi **data demo fiktif** (59 pasien, histori Maret–Mei 2026).
Data pasien penelitian yang asli **tetap hanya di laptop** — jangan pernah
mengunggah `database/database.sqlite` dari laptop ke server.

## 1. Sewa VPS

| Kebutuhan | Minimal |
|---|---|
| Sistem operasi | **Ubuntu 24.04 LTS** |
| CPU / RAM | 1 vCPU / 1 GB (2 GB lebih lega) |
| Disk | 20 GB |
| Lokasi | Indonesia atau Singapura |

Setelah VPS aktif, catat **alamat IP** dan **password root** (atau kunci SSH) dari panel penyedia.

## 2. Siapkan alamat web

- **Tanpa beli domain:** pakai `sslip.io`. IP `103.150.10.20` → alamat `103-150-10-20.sslip.io`
  (langsung aktif, HTTPS tetap bisa).
- **Punya domain sendiri:** buat DNS record `A` yang mengarah ke IP VPS, mis. `prb.namadomain.id`.

## 3. Pastikan kode terbaru sudah di GitHub

Di laptop: commit lalu push ke `origin master`. Server mengambil kode dari GitHub.

## 4. Masuk ke VPS

Dari terminal laptop (PowerShell juga bisa):

```bash
ssh root@103.150.10.20
```

## 5. Izinkan VPS membaca repo (repo private)

Di VPS:

```bash
ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```

Salin hasilnya ke GitHub → repo **smart-prb** → **Settings → Deploy keys → Add deploy key**
(cukup *read-only*, jangan centang *Allow write access*).

## 6. (VPS RAM 1 GB) Tambah swap

Build aset frontend butuh memori; tanpa swap bisa gagal di RAM 1 GB.

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

## 7. Ambil kode & jalankan instalasi

```bash
git clone git@github.com:tropicalforet/smart-prb.git /var/www/smart-prb
sudo bash /var/www/smart-prb/deploy/setup-vps.sh 103-150-10-20.sslip.io email-kamu@contoh.com
```

Ganti alamat dan email sesuai milikmu. Skrip memasang Nginx, PHP 8.5, Node 22, membuat database,
akun admin, data demo, lalu HTTPS. Lama prosesnya ±10 menit.

**Catat password admin** yang muncul di langkah `5/7` (baris *password acak*). Password ini hanya
ditampilkan sekali. Setelah login, ganti lewat menu **Akun → Ganti Password**.

## 8. Buat akun untuk dosen

Supaya dosen tidak memakai akun admin:

```bash
cd /var/www/smart-prb
ADMIN_EMAIL=dosen@smartprb.id ADMIN_NAME="Dosen Pembimbing" php artisan db:seed --class=AdminUserSeeder --force
```

Password acak untuk akun dosen tampil di layar — kirimkan ke dosen secara pribadi.

## 9. Memperbarui setelah ada revisi

Setiap selesai revisi di laptop dan sudah di-push ke GitHub:

```bash
ssh root@103.150.10.20
sudo bash /var/www/smart-prb/deploy/update.sh
```

Data demo dan akun di server tidak berubah saat update.

## 10. Mengulang data demo dari awal

Jika data demo sudah berantakan setelah banyak dicoba:

```bash
cd /var/www/smart-prb
php artisan migrate:fresh --force
php artisan db:seed --class=AdminUserSeeder --force
php artisan db:seed --class=DemoDataSeeder --force
chown -R www-data:www-data database storage
```

`migrate:fresh` menghapus **semua** isi database server (termasuk akun), lalu akun admin dibuat ulang
dengan password acak baru.

## Masalah umum

| Gejala | Penyebab & solusi |
|---|---|
| `Permission denied (publickey)` saat `git clone` | Deploy key belum ditambahkan di GitHub (langkah 5) |
| `npm run build` berhenti / *Killed* | RAM kurang → tambah swap (langkah 6) |
| Certbot gagal | Domain belum mengarah ke IP VPS, atau port 80 tertutup di firewall panel penyedia |
| Halaman error 500 | Lihat `tail -50 /var/www/smart-prb/storage/logs/laravel.log` |
| Error izin menulis file / database | `chown -R www-data:www-data /var/www/smart-prb/{storage,bootstrap/cache,database}` |
