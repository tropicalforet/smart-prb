# Hosting SMART PRB di Hostinger Web Hosting

Panduan ini memindahkan aplikasi **beserta data yang sama persis dengan laptop**
(pasien, histori, tindak lanjut, status, akun, Audit Log).

> **Data pasien asli.** Pastikan izin etik dan izin apotek membolehkan data disimpan
> di server internet. Database dikirim langsung dari laptop ke server (scp), **tidak
> lewat GitHub**, dan disimpan di luar folder web sehingga tidak bisa diunduh lewat browser.

## 0. Sebelum membeli

- Paket **Premium** atau di atasnya (ada akses **SSH** dan **Composer 2** — di Hostinger perintahnya `composer2`).
- Tanyakan/cek di hPanel bahwa **PHP 8.5** tersedia. Proyek ini wajib PHP 8.5.

## 1. Atur hPanel

1. Tambahkan domain (atau pakai domain sementara dari Hostinger).
2. **Advanced → PHP Configuration** → pilih **PHP 8.5**.
3. **Advanced → SSH Access** → aktifkan, lalu catat **IP**, **port** (biasanya `65002`),
   **username** (`u123456789`), dan password SSH.

Di bawah ini, ganti `u123456789`, `IP-SERVER`, dan `prb-namakamu.com` dengan milikmu.

## 2. Pastikan kode terbaru sudah di GitHub

Di laptop: commit lalu push ke `origin master`.

## 3. Masuk SSH & izinkan server membaca repo

Dari PowerShell di laptop:

```bash
ssh -p 65002 u123456789@IP-SERVER
```

Di server:

```bash
php -v
ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```

`php -v` harus menunjukkan **8.5**. Salin hasil `cat` ke GitHub → repo **smart-prb** →
**Settings → Deploy keys → Add deploy key** (*read-only*).

## 4. Ambil kode di server

```bash
cd ~/domains/prb-namakamu.com
git clone git@github.com:tropicalforet/smart-prb.git
```

## 5. Siapkan file di laptop

Di folder proyek (PowerShell):

```bash
powershell -ExecutionPolicy Bypass -File deploy\siapkan-hostinger.ps1
```

Hasilnya di `deploy\keluaran\`: `build.tar.gz` (tampilan web) dan `database.sqlite`
(salinan database laptop). Folder ini tidak pernah masuk Git.

## 6. Unggah ke server

```bash
scp -P 65002 deploy\keluaran\build.tar.gz deploy\keluaran\database.sqlite u123456789@IP-SERVER:domains/prb-namakamu.com/smart-prb/deploy/keluaran/
```

## 7. Pasang di server

Kembali ke jendela SSH:

```bash
cd ~/domains/prb-namakamu.com/smart-prb
bash deploy/pasang-hostinger.sh prb-namakamu.com
```

Skrip membuat `.env` production, memasang dependensi, memasang tampilan dan database,
lalu mengarahkan `public_html` ke folder `public/` aplikasi (isi `public_html` lama dipindah
ke `public_html.lama-…`).

## 8. Aktifkan HTTPS & coba

1. hPanel → **Security → SSL** → pastikan aktif, nyalakan **Force HTTPS**.
2. Buka `https://prb-namakamu.com` dan login dengan **akun yang sama seperti di laptop**
   (akun ikut tersalin bersama database).

### Akun untuk dosen

```bash
cd ~/domains/prb-namakamu.com/smart-prb
ADMIN_EMAIL=dosen@smartprb.id ADMIN_NAME="Dosen Pembimbing" php artisan db:seed --class=AdminUserSeeder --force
```

Password acak akun dosen tampil di layar — kirimkan ke dosen secara pribadi.

## Memperbarui

**Hanya kode/tampilan** (data di server tetap):

```bash
# laptop
powershell -ExecutionPolicy Bypass -File deploy\siapkan-hostinger.ps1 -TanpaDatabase
scp -P 65002 deploy\keluaran\build.tar.gz u123456789@IP-SERVER:domains/prb-namakamu.com/smart-prb/deploy/keluaran/
```

```bash
# server
cd ~/domains/prb-namakamu.com/smart-prb && git pull && bash deploy/pasang-hostinger.sh prb-namakamu.com
```

**Data juga diganti dengan data laptop:** jalankan langkah 5–7 lagi. Perubahan yang dibuat
lewat web di server (mis. tindak lanjut baru) **tertimpa**; database server sebelumnya
dibackup otomatis ke `database/database.sqlite.backup-…`.

## Ganti domain (dari domain sementara ke domain asli)

1. **Siapkan domain.** Beli di hPanel → **Domain**, atau pakai domain yang sudah dimiliki dan arahkan
   *nameserver*-nya ke nameserver Hostinger yang tertera di hPanel.
2. **Hubungkan ke website.** hPanel → website → **Dashboard → Hubungkan domain** (atau pengaturan website
   → ganti domain). Tunggu sampai domain aktif dan SSL terpasang (bisa beberapa menit sampai beberapa jam).
3. **Cari folder aplikasi** di SSH. Hostinger bisa memindahkan folder ke nama domain baru:

   ```bash
   ls ~/domains
   ```

   Aplikasi ada di `~/domains/<domain-lama-atau-baru>/smart-prb`.
4. **Pasang ulang dengan domain baru** (data dan akun tidak berubah):

   ```bash
   cd ~/domains/<folder-dari-langkah-3>/smart-prb
   bash deploy/pasang-hostinger.sh domain-baru.com
   ```

   Skrip memperbarui `APP_URL` di `.env`, membuat ulang cache, dan mengarahkan `public_html` domain baru
   ke aplikasi. Semua pengguna perlu login ulang.

## Backup data dari server ke laptop

```bash
scp -P 65002 u123456789@IP-SERVER:domains/prb-namakamu.com/smart-prb/database/database.sqlite database-server.sqlite
```

## Masalah umum

| Gejala | Penyebab & solusi |
|---|---|
| Skrip berhenti di "butuh PHP 8.5" | Pilih PHP 8.5 di hPanel lalu buka ulang SSH, atau jalankan dengan `PHP_BIN=/opt/alt/php85/usr/bin/php bash deploy/pasang-hostinger.sh ...` |
| Skrip berhenti di "Butuh Composer 2" | Cek `composer2 --version`; jalankan `COMPOSER_BIN=$(command -v composer2) bash deploy/pasang-hostinger.sh ...` |
| `Permission denied (publickey)` saat clone | Deploy key belum ditambahkan di GitHub (langkah 3) |
| Halaman masih tampilan default Hostinger | Jalankan ulang langkah 7; cek `ls -l ~/domains/prb-namakamu.com/public_html` harus menunjuk ke `.../smart-prb/public` |
| Error 500 | `tail -50 ~/domains/prb-namakamu.com/smart-prb/storage/logs/laravel.log` |
| Tampilan berantakan / tanpa gaya | `build.tar.gz` belum diunggah → ulangi langkah 5–7 |
