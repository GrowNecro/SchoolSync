# SchoolSync

SchoolSync adalah aplikasi otomatisasi laboratorium komputer berbasis Windows Batch dan PowerShell.

## Struktur awal proyek

- Install.bat: menyalin file ke C:\SchoolSync dan membuat shortcut startup
- Uninstall.bat: menghapus instalasi
- SchoolSync.bat: launcher untuk PowerShell
- SchoolSync.ps1: logika utama aplikasi
- config.json: konfigurasi utama
- version.txt: versi aplikasi
- projects/: file proyek contoh

## Cara mulai

1. Jalankan Install.bat sebagai administrator.
2. Pastikan file berada di C:\SchoolSync.
3. Jalankan SchoolSync.bat untuk menguji alur aplikasi.

## Fitur yang sudah tersedia di versi awal

- Installer dan uninstall otomatis
- Launcher startup
- Pembacaan konfigurasi lokal
- Schedule manager sederhana
- Project update awal
- Browser manager via Microsoft Edge
- Auto launcher berbasis mapping
- Logging sederhana
