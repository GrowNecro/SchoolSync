# SchoolSync Control

Panel admin PHP tanpa framework untuk shared hosting Rumahweb. Akun admin, konfigurasi, dan metadata proyek disimpan di MySQL. File proyek Roblox tetap disimpan di folder `uploads`, sedangkan installer dan aplikasi Windows disediakan langsung oleh panel tanpa GitHub.

## Kebutuhan hosting

- PHP 8.0 atau lebih baru
- MySQL 5.7/8.0 atau MariaDB yang mendukung InnoDB
- Ekstensi PHP `pdo_mysql`
- HTTPS aktif
- Ekstensi PHP standar: session, json, fileinfo
- Folder `config` dan `uploads` dapat ditulis oleh PHP saat pemasangan

## Pemasangan di Rumahweb

1. Buat folder, misalnya `public_html/schoolsync`.
2. Upload **isi root proyek** atau ekstrak paket `SchoolSync-Control-Rumahweb.zip` ke folder tersebut.
3. Buat database dan pengguna MySQL melalui cPanel, lalu berikan seluruh hak akses pengguna tersebut ke databasenya.
4. Pastikan file `.htaccess` ikut terunggah.
5. Buka `https://domainanda.sch.id/schoolsync`, masukkan koneksi MySQL, kemudian buat akun admin pertama.
6. Unduh installer dari bagian **Hubungkan komputer lab**.
7. Jalankan installer sebagai administrator pada setiap komputer lab.

Wizard membuat tiga tabel otomatis: `schoolsync_admins`, `schoolsync_settings`, dan `schoolsync_projects`. SQL manual juga tersedia di `tools/database/schema.sql` bila hosting membutuhkannya.

Jika panel menampilkan kesalahan izin, ubah permission folder `config` dan `uploads` menjadi `755`. Gunakan `775` hanya jika konfigurasi server membutuhkannya. Kredensial tersimpan di `config/database.php` dan folder `config` ditolak dari akses browser.

## Endpoint komputer lab

- `api/config.php` mengirim konfigurasi aktif.
- `api/project.php?file=Nama.rbxl` mengirim proyek terpilih.
- `api/client.php?file=...` mengirim aplikasi dan versi terbaru.
- `api/installer.php` menghasilkan installer yang sudah terhubung ke panel.

Folder `storage` dan `uploads` tidak boleh dapat dibuka langsung dari browser. Uji setelah upload dan pastikan akses ke kedua folder menghasilkan status `403 Forbidden`.

## Struktur root

- `index.php`, `app.php`, `api/`, dan `assets/` adalah panel web utama.
- `config/` menyimpan koneksi MySQL, `storage/` menampung data JSON lama untuk migrasi, dan `uploads/` menyimpan file proyek.
- `tools/` berisi aplikasi Windows, installer, dokumentasi, log pengembangan, serta utilitas. Folder ini ditolak dari akses browser oleh `.htaccess`; file klien hanya dikeluarkan melalui endpoint API yang sudah dibatasi.
