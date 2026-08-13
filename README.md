# SchoolSync Control

SchoolSync adalah panel Laravel + MySQL untuk mengatur komputer laboratorium Windows. Panel mengelola jadwal, website, launcher, shutdown, proyek Roblox, pustaka file, installer, dan pembaruan aplikasi tanpa GitHub sebagai sumber klien.

## Teknologi

- Laravel 13
- PHP 8.3+
- MySQL 5.7+/8.0 atau MariaDB
- Blade, Vite, dan CSS responsif
- PowerShell/Batch untuk klien Windows

## Menjalankan secara lokal

1. Salin `.env.example` menjadi `.env` dan isi koneksi MySQL.
2. Buat database kosong.
3. Jalankan:

```powershell
composer install
php artisan key:generate
php artisan migrate --seed
npm.cmd install
npm.cmd run build
php artisan serve
```

4. Buka aplikasi dan masuk menggunakan akun bawaan:

```text
Username: admin
Password: password
```

Seeder menggunakan `firstOrCreate`, sehingga menjalankannya kembali tidak mereset password yang sudah diubah melalui dashboard.

## Penyimpanan

- `users`: akun admin Laravel.
- `settings`: konfigurasi aktif SchoolSync.
- `projects`: metadata seluruh file, ukuran, SHA-256, dan penanda ekstraksi ZIP.
- `storage/app/private/projects`: gambar, dokumen, video, ZIP, proyek, dan file lain yang tidak dapat dibuka langsung dari browser.
- `tools`: installer, klien PowerShell, dokumentasi lama, serta arsip panel PHP sebelumnya.

Panel menerima semua jenis file sampai 100 MB per file. Klien menyinkronkannya ke `C:\SchoolSync\downloads`. Arsip ZIP tetap disimpan dan isinya otomatis diekstrak ke subfolder dengan nama yang sama. Ekstraksi memvalidasi setiap jalur arsip agar tidak dapat menulis ke luar folder `downloads`.

## Route klien Windows

- `client/config`
- `client/files`
- `client/commands?after=0`
- `client/heartbeat`
- `download?file=Nama-file.zip` untuk file pustaka
- `download?client=SchoolSync.ps1` untuk berkas aplikasi klien
- `installer` untuk installer siap pakai

Semua unduhan berasal langsung dari URL aplikasi, bukan GitHub. Endpoint PHP lama sudah dihapus dan klien versi 1.6.0 menggunakan route Laravel. Installer membuat heartbeat Windows yang berjalan sebagai `SYSTEM` sejak komputer menyala serta klien interaktif saat pengguna login. Dashboard membedakan status `Menyala`, `Siap Edge`, dan `Offline`. Heartbeat dikirim setiap 30 detik dan komputer dianggap offline setelah 90 detik tanpa heartbeat. Klien juga memeriksa versi terbaru setiap 60 detik; saat ada pembaruan, file aplikasi diunduh dari panel dan proses dimulai ulang otomatis.

## Push lokal ke GitHub

Semua perubahan dikirim melalui branch `main`. Sebelum push, build Vite dan pengujian Laravel wajib berhasil.

```powershell
npm.cmd run build
php artisan test
git add .
git commit -m "jelaskan perubahan"
powershell -ExecutionPolicy Bypass -File deployment/local/push-main.ps1
```

`push-main.ps1` membangun ulang aset dan menjalankan tes sekali lagi sebelum `git push origin main`. Push dibatalkan jika build/tes gagal atau hasil build belum di-commit.

## Deployment Rumahweb melalui GitHub

Simpan repository Laravel di luar `public_html`, yaitu `~/SchoolSync`, dan gunakan document root subdomain `~/public_html/sch.grownecro.my.id`. Jangan menaruh `.env`, `vendor`, `storage`, atau `tools` di document root. Folder `public/build` wajib ikut di-commit karena build dilakukan di lokal.

1. Untuk pertama kali, clone repository GitHub ke `~/SchoolSync`.
2. Buat `.env` production dan database MySQL melalui cPanel. Berikan pengguna database **ALL PRIVILEGES**.
3. Untuk deployment pertama maupun setiap pembaruan berikutnya, cukup jalankan updater yang sama:

```bash
cd ~/SchoolSync
bash deployment/rumahweb/update-main.sh
```

Updater menjalankan `git pull --ff-only origin main`, memvalidasi aset Vite, membuat backup, memasang dependency production, membuat `APP_KEY` pada instalasi pertama, menjalankan migrasi dan seeder, membuat cache Laravel, lalu menyelaraskan isi `public` ke document root tanpa menghapus upload atau konten hosting lain. Nilai `SITE_URL` diterapkan ke `APP_URL`, sehingga URL installer dan endpoint klien mengikuti alamat aplikasi production.

Updater tidak menjalankan `php artisan storage:link`. Untuk kompatibilitas shared hosting, updater membuat `public_html/sch.grownecro.my.id/storage` secara manual lalu mengarahkan `PUBLIC_FILES_ROOT` dan `PUBLIC_FILES_URL` langsung ke folder tersebut.

Updater akan berhenti jika repository hosting memiliki perubahan lokal atau file yang belum dilacak. Backup setiap deployment disimpan di `storage/app/deployment-backups`.

Jika muncul `SQLSTATE[HY000] [1044]`, hubungkan ulang pengguna MySQL ke database dengan seluruh hak akses lalu jalankan `php artisan migrate --force`.

## Verifikasi

```powershell
php artisan migrate:status
php artisan test
npm.cmd run build
```
