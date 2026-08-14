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

Semua unduhan berasal langsung dari URL aplikasi, bukan GitHub. Endpoint PHP lama sudah dihapus dan klien versi 2.0.4 menggunakan route Laravel. Installer membuat heartbeat Windows yang berjalan sebagai `SYSTEM` sejak komputer menyala serta klien interaktif saat pengguna login. Untuk menjaga paket Rumahweb Medium dan website lain dalam akun yang sama, heartbeat dikirim setiap 1–2 menit, status dashboard diperbarui setiap menit, komputer dianggap offline setelah 3 menit, pemeriksaan versi serta sinkronisasi file dilakukan setiap 10 menit, dan awal proses diberi jeda acak agar request banyak komputer tidak datang bersamaan.

Untuk Roblox Studio, klien mencari `RobloxStudioBeta.exe` pada `%LOCALAPPDATA%\Roblox\Versions`, memilih instalasi terbaru, dan membuka proyek aktif dari `C:\SchoolSync\projects` bila tersedia.

Folder kerja `C:\SchoolSync\projects` disinkronkan dua arah. File baru atau berubah dari komputer klien diunggah ke penyimpanan privat server berdasarkan nama dan identitas komputer, lalu dapat dilihat serta diunduh admin melalui halaman **File dari komputer**. Semua jenis file didukung hingga 100 MB per file.

Klien 2.0.4 memakai pairing token per perangkat. Komputer baru otomatis disetujui untuk menerima konfigurasi, file, dan perintah; admin tetap dapat mencabut izin perangkat tertentu melalui menu **Komputer & grup**. Panel mendukung target semua komputer, grup, atau satu komputer; laporan hasil perintah; inventaris perangkat; riwayat versi file dan pemulihan; multi-jadwal; serta mode ujian berbasis pemblokiran proses selama sesi. Perubahan jadwal atau mode ujian mengirim sinyal langsung dan biasanya diterapkan sekitar 20 detik, sedangkan polling konfigurasi penuh hanya menjadi cadangan setiap 2 menit. Setelah aktif, proses SYSTEM tetap mendeteksi Roblox Player secara lokal setiap detik tanpa request tambahan ke server.

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

Updater akan berhenti jika repository hosting memiliki perubahan lokal atau file yang belum dilacak. Backup setiap deployment disimpan di `storage/app/deployment-backups`. Untuk mengurangi dampak ke website lain dalam akun Rumahweb yang sama, proses CLI dijalankan dengan prioritas rendah jika tersedia dan Composer dilewati ketika `composer.lock` tidak berubah. Updater hanya menyalin aset ke document root subdomain yang ditentukan oleh `PUBLIC_ROOT`.

Setelah pembaruan ke klien 2.0.4, semua komputer lama yang masih menunggu dan semua instalasi baru otomatis disetujui. Gunakan menu **Komputer & grup** untuk memasukkan perangkat ke grup atau menonaktifkan izin perangkat tertentu. Status setiap perintah dapat dipantau melalui menu **Aktivitas**.

Jika muncul `SQLSTATE[HY000] [1044]`, hubungkan ulang pengguna MySQL ke database dengan seluruh hak akses lalu jalankan `php artisan migrate --force`.

## Verifikasi

```powershell
php artisan migrate:status
php artisan test
npm.cmd run build
```
