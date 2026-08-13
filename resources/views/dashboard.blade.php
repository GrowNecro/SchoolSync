@extends('layouts.app')

@section('title', 'Dashboard')
@section('section', 'DASHBOARD')

@section('content')
    <section class="hero">
        <div>
            <span class="eyebrow">SchoolSync · Lab control</span>
            <h1>Ruang kelas siap,<br>sebelum siswa masuk.</h1>
            <p>Atur komputer laboratorium, kirim file, dan buka materi di Edge langsung dari panel Laravel ini.</p>
        </div>
        <div class="hero-status"><span>Status konfigurasi</span><strong><i></i> Siap digunakan</strong><small>Terakhir disimpan {{ $setting->updated_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</small></div>
    </section>

    <section class="metrics">
        <article><span>Jadwal aktif</span><strong>{{ $days[$setting->schedule_day] ?? '-' }}</strong><small>{{ substr($setting->start_time, 0, 5) }}—{{ substr($setting->end_time, 0, 5) }} WIB</small></article>
        <article><span>File tersedia</span><strong>{{ $projects->count() }} file</strong><small>{{ $setting->project?->filename ?? 'Belum ada proyek aktif' }}</small></article>
        <article><span>Otomatisasi</span><strong>{{ count($setting->launcher ?? []) }} aplikasi</strong><small>{{ count($setting->browser ?? []) }} website terjadwal</small></article>
        <article><span>Komputer aktif</span><strong><span data-active-count>{{ $active_count }}</span> komputer</strong><small>Dari <span data-total-count>{{ $total_count }}</span> komputer terdaftar</small></article>
    </section>

    <section class="panel" data-computer-status data-status-url="{{ route('status.computers') }}">
        <div class="panel-heading"><div><span class="step">PC</span><h2>Komputer terhubung</h2><p>Status diperbarui otomatis. Komputer dinyatakan offline setelah 90 detik tanpa heartbeat.</p></div><span class="live-indicator"><i></i> Langsung</span></div>
        <div class="computer-list" data-computer-list>
            @forelse ($computers as $computer)
                <article>
                    <span class="computer-dot {{ $computer['active'] ? 'online' : '' }}"></span>
                    <div><strong>{{ $computer['name'] }}</strong><small>Klien {{ $computer['version'] }} · {{ $computer['last_seen_label'] }}{{ $computer['active'] && ! $computer['interactive'] ? ' · Menunggu pengguna login' : '' }}</small></div>
                    <span class="{{ $computer['active'] ? 'active-badge' : 'muted-badge' }}">{{ $computer['interactive'] ? 'Siap Edge' : ($computer['active'] ? 'Menyala' : 'Offline') }}</span>
                </article>
            @empty
                <div class="empty">Belum ada komputer yang mengirim heartbeat.</div>
            @endforelse
        </div>
    </section>

    <section class="panel action-panel">
        <div class="panel-heading"><div><span class="step">GO</span><h2>Buka sekarang</h2><p>Kirim alamat atau pencarian ke Microsoft Edge pada semua komputer yang siap.</p></div></div>
        <form method="post" action="{{ route('actions.open-url') }}" class="instant-form">
            @csrf
            <label>Alamat atau teks pencarian<input type="text" name="url" value="{{ old('url') }}" placeholder="youtube.com atau materi Scratch kelas 8" autocapitalize="off" spellcheck="false" required></label>
            <button class="button primary" type="submit">Buka di Edge sekarang</button>
        </form>
        <small class="form-note">Perintah berlaku selama 10 menit dan diterima klien aktif dalam beberapa detik.</small>
    </section>
@endsection
