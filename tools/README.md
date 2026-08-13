# SchoolSync

SchoolSync adalah aplikasi otomatisasi laboratorium komputer berbasis Windows Batch, PowerShell, dan panel web PHP. Seluruh aplikasi, konfigurasi, dan proyek didistribusikan melalui panel web di shared hosting tanpa GitHub.

Panel menggunakan MySQL untuk akun admin, konfigurasi kelas, dan metadata proyek. File proyek tetap disimpan di folder `uploads` pada hosting.

## Struktur proyek

- `Install.bat`: memasang aplikasi ke `C:\SchoolSync` dari panel web
- `Uninstall.bat`: menghapus instalasi
- `SchoolSync.bat`: launcher PowerShell
- `SchoolSync.ps1`: logika utama aplikasi
- `config.json`: konfigurasi lokal cadangan
- `server.json`: alamat panel web SchoolSync
- `version.txt`: versi aplikasi
- root proyek: panel PHP dan endpoint distribusi klien
- `projects/`: file proyek lokal
- `database/schema.sql`: schema MySQL untuk instalasi manual

## Cara mulai

1. Buat database dan pengguna MySQL melalui cPanel Rumahweb.
2. Upload isi root proyek atau ekstrak paket Rumahweb ke hosting, lalu buka alamatnya untuk menghubungkan MySQL dan membuat akun admin.
3. Buka bagian **Hubungkan komputer lab**, lalu unduh installer.
4. Jalankan installer tersebut sebagai administrator pada setiap komputer lab.
5. Kelola jadwal, aplikasi, website, dan proyek dari panel web.
6. Jalankan `C:\SchoolSync\SchoolSync.bat` untuk menguji alurnya.

Installer lokal juga dapat dijalankan dengan alamat panel:

```bat
Install.bat "https://domainanda.sch.id/schoolsync"
```

Installer wajib terhubung ke panel dan lokasi instalasi tetap `C:\SchoolSync`.

## Fitur

- Installer dan uninstall otomatis
- Startup otomatis saat pengguna login
- Pembaruan aplikasi langsung dari panel web
- Jadwal sesi kelas
- Upload dan distribusi proyek Roblox
- Pembukaan website melalui Microsoft Edge
- Auto launcher berbasis mapping
- Peringatan dan shutdown otomatis
- Logging lokal
- Login admin dan penggantian kata sandi panel
