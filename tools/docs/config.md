# SchoolSync v1.0

## Deskripsi

SchoolSync adalah aplikasi otomatisasi laboratorium komputer berbasis Windows Batch (.bat) dan PowerShell (.ps1). Aplikasi berjalan otomatis setiap kali pengguna login ke Windows. Seluruh aplikasi, konfigurasi, dan proyek disediakan oleh panel web SchoolSync di shared hosting sehingga administrator tidak perlu mengedit GitHub atau memperbarui setiap komputer secara manual. Akun admin, konfigurasi, dan metadata proyek disimpan pada MySQL.

Versi pertama difokuskan untuk pembelajaran Roblox Studio, namun arsitektur dibuat fleksibel agar dapat digunakan untuk aplikasi pembelajaran lainnya.

## Platform

- Windows 10 / Windows 11
- Menggunakan Batch (.bat) dan PowerShell (.ps1)
- Tidak memerlukan software tambahan selain PowerShell bawaan Windows

## Struktur Lokal

```text
C:\SchoolSync
├── Install.bat
├── Uninstall.bat
├── SchoolSync.bat
├── SchoolSync.ps1
├── config.json
├── version.txt
├── projects\
├── downloads\
└── logs\
```

Semua folder dibuat otomatis saat instalasi.

## Instalasi

Pengguna cukup menjalankan:

```bat
Install.bat
```

Installer akan:

- Membuat folder C:\SchoolSync
- Membuat folder projects, downloads, dan logs
- Menyalin seluruh file SchoolSync
- Membuat shortcut SchoolSync.bat ke C:\ProgramData\Microsoft\Windows\Start Menu\Programs\Startup

Setelah itu SchoolSync akan berjalan otomatis setiap login Windows.

## Struktur Panel Web

```text
SchoolSync
├── version.txt
├── config.json
├── SchoolSync.bat
├── SchoolSync.ps1
└── projects
    ├── Pertemuan-01.rbxl
    ├── Pertemuan-02.rbxl
    └── ...
```

## Konfigurasi

Contoh konfigurasi:

```jsonc
{
    "schedule": {
        "day": "Friday",
        "start": "14:00",
        "end": "16:00"
    },

    "project": "Pertemuan-01.rbxl",

    "browser": [
        "https://classroom.google.com"
    ],

    "launcher": [
        "edge",
        "roblox"
    ],

    "shutdown": {
        "enabled": true,
        "warning": 10
    }
}
```

### Penjelasan Konfigurasi

- schedule.day: hari eksekusi updater
- schedule.start: jam mulai pembelajaran
- schedule.end: jam selesai pembelajaran
- project: nama file project di folder projects
- browser: daftar website yang dibuka menggunakan Microsoft Edge
- launcher: daftar aplikasi yang dijalankan otomatis
- shutdown: pengaturan shutdown otomatis

## Fitur

### 1. Self Update

Saat SchoolSync dijalankan:

- Download version.txt
- Bandingkan dengan versi lokal
- Jika versi berbeda, unduh SchoolSync.bat dan SchoolSync.ps1 dari panel web
- Replace file lokal
- Update version.txt

Catatan: jangan mengganti file yang sedang dijalankan. Gunakan file sementara lalu lakukan replace setelah proses selesai.

### 2. Remote Configuration

- Download konfigurasi terbaru dari API panel web
- Seluruh konfigurasi utama aplikasi berasal dari panel web
- Tidak boleh ada konfigurasi yang di-hardcode pada script

### 3. Schedule Manager

Berdasarkan konfigurasi schedule:

```json
"schedule": {
    "day": "Friday",
    "start": "14:00",
    "end": "16:00"
}
```

Aturan:

- Hari tidak sesuai → exit
- Hari sesuai tetapi jam belum mulai → tunggu hingga jam mulai
- Jam berada di antara start dan end → lanjut
- Jam sudah melewati end → exit

### 4. Update Manager

- Download project sesuai konfigurasi
- Contoh: project = Pertemuan-01.rbxl
- File diunduh dari /projects/Pertemuan-01.rbxl ke C:\SchoolSync\projects\

### 5. Browser Manager

Semua website pada konfigurasi dibuka menggunakan Microsoft Edge, bukan browser default Windows.

### 6. Auto Launcher

Menjalankan aplikasi sesuai daftar pada konfigurasi.

Launcher yang didukung pada versi pertama:

| Launcher | Fungsi |
| --- | --- |
| edge | Membuka Microsoft Edge |
| roblox | Membuka Roblox Studio beserta project |
| vscode | Membuka Visual Studio Code |
| scratch | Membuka Scratch Desktop |
| construct | Membuka Construct 3 Desktop |
| python | Membuka Python IDLE |

Implementasi menggunakan sistem mapping agar penambahan launcher baru hanya memerlukan penambahan mapping tanpa mengubah logika utama.

### 7. Auto Shutdown

Jika aktif:

```json
"shutdown": {
    "enabled": true,
    "warning": 10
}
```

- 10 menit sebelum selesai: tampilkan popup "Praktikum akan berakhir dalam 10 menit."
- Saat waktu selesai: jalankan shutdown /s /t 0

## Alur Program

```text
Windows Login
  ↓
Shortcut Startup
  ↓
SchoolSync.bat
  ↓
Self Update
  ↓
Remote Configuration
  ↓
Schedule Manager
  ├── Tidak sesuai → Exit
  └── Sesuai
        ↓
Update Manager
        ↓
Browser Manager
        ↓
Auto Launcher
        ↓
Menunggu Jam Selesai
        ↓
Auto Shutdown
```

## Coding Guidelines

- SchoolSync.bat hanya sebagai launcher
- Seluruh logika utama berada pada SchoolSync.ps1
- Seluruh aplikasi, konfigurasi, dan proyek berasal dari panel web
- Gunakan variabel untuk seluruh path
- Gunakan Microsoft Edge untuk seluruh website
- Gunakan launcher berbasis mapping
- Tangani seluruh kegagalan download dengan error handling
- Simpan log sederhana pada folder logs
- Struktur kode harus modular dan mudah dikembangkan
- Implementasikan fitur secara bertahap dan pastikan setiap fitur selesai sebelum membuat fitur berikutnya

## Target v1.0

- Install otomatis
- Startup otomatis
- Self Update
- Remote Configuration
- Schedule Manager
- Update Project
- Browser Manager (Microsoft Edge)
- Auto Launcher (Mapping)
- Auto Shutdown

## Catatan

SchoolSync dirancang agar komputer laboratorium dapat menyiapkan lingkungan belajar otomatis hanya berdasarkan pengaturan di panel web. Fitur tambahan seperti checksum, plugin manager, backup, dashboard guru, dan multi-subjek dapat ditambahkan tanpa mengubah arsitektur dasar.
