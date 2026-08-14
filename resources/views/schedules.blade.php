@extends('layouts.app')

@section('title', 'Multi-jadwal')
@section('section', 'MULTI-JADWAL')

@section('content')
    <section class="page-heading"><span class="eyebrow">Otomatisasi</span><h1>Multi-jadwal kelas</h1><p>Setiap sesi dapat memiliki target, proyek, aplikasi, website, dan shutdown sendiri. {{ config('schoolsync.exam_mode_enabled', false) ? 'Aturan ujian dapat diatur per jadwal.' : 'Mode ujian sedang dinonaktifkan sementara.' }}</p></section>

    <section class="panel">
        <div class="panel-heading"><div><span class="step">NEW</span><h2>Tambah jadwal</h2><p>Buat sesi baru untuk kelas atau kelompok komputer tertentu.</p></div></div>
        @include('partials.schedule-form', ['schedule' => null])
    </section>

    @php
        $excludedComputers = old('shutdown_excluded_computers', $setting->shutdown_excluded_computers ?? []);
        $knownComputerKeys = $computerNames->map(fn ($name) => mb_strtolower($name))->all();
        $manualExcludedComputers = collect($setting->shutdown_excluded_computers ?? [])
            ->reject(fn ($name) => in_array(mb_strtolower($name), $knownComputerKeys, true))
            ->implode("\n");
    @endphp
    <section class="panel">
        <div class="panel-heading"><div><span class="step">PC</span><h2>Pengecualian shutdown</h2><p>Komputer berikut tetap menyala meskipun jadwal atau perintah shutdown dijalankan.</p></div></div>
        <form method="post" action="{{ route('schedules.exclusions.update') }}" class="config-form">
            @csrf @method('PUT')
            <div class="shutdown-exclusions">
                <div class="computer-check-grid">
                    @forelse ($computerNames as $computerName)
                        <label class="check"><input type="checkbox" name="shutdown_excluded_computers[]" value="{{ $computerName }}" @checked(in_array(mb_strtolower($computerName), array_map('mb_strtolower', $excludedComputers), true))><span><b>{{ $computerName }}</b><small>Tetap menyala</small></span></label>
                    @empty
                        <span class="empty-inline">Belum ada komputer terdaftar.</span>
                    @endforelse
                </div>
                <label>Tambahkan nama komputer manual <span class="hint">Satu per baris</span><textarea name="shutdown_excluded_manual" rows="3" placeholder="LAB-GURU&#10;SERVER-KELAS">{{ old('shutdown_excluded_manual', $manualExcludedComputers) }}</textarea></label>
                <button class="button secondary" type="submit">Simpan pengecualian</button>
            </div>
        </form>
    </section>

    @forelse ($schedules as $schedule)
        <section class="panel schedule-card">
            <div class="panel-heading">
                <div><span class="step">{{ $loop->iteration }}</span><h2>{{ $schedule->name }}</h2><p>{{ $days[$schedule->schedule_day] ?? $schedule->schedule_day }} · {{ substr($schedule->start_time, 0, 5) }}—{{ substr($schedule->end_time, 0, 5) }} · {{ $schedule->enabled ? 'Aktif' : 'Nonaktif' }}</p></div>
                <div class="schedule-heading-actions">
                    @if (config('schoolsync.exam_mode_enabled', false))
                        <span class="{{ $schedule->exam_enabled ? 'warning-badge' : 'muted-badge' }}">Mode ujian {{ $schedule->exam_enabled ? 'aktif' : 'mati' }}</span>
                        <form method="post" action="{{ route('schedules.exam-mode.update', $schedule) }}">@csrf @method('PATCH')<input type="hidden" name="exam_enabled" value="{{ $schedule->exam_enabled ? 0 : 1 }}"><button class="button compact {{ $schedule->exam_enabled ? 'secondary' : 'primary' }}" type="submit">{{ $schedule->exam_enabled ? 'Matikan sekarang' : 'Aktifkan sekarang' }}</button></form>
                    @else
                        <span class="muted-badge">Mode ujian nonaktif sementara</span>
                    @endif
                </div>
            </div>
            <details><summary>Edit jadwal</summary>@include('partials.schedule-form', ['schedule' => $schedule])</details>
            <form method="post" action="{{ route('schedules.destroy', $schedule) }}" class="inline-delete" onsubmit="return confirm('Hapus jadwal {{ $schedule->name }}?')">@csrf @method('DELETE')<button class="delete-button" type="submit">Hapus jadwal</button></form>
        </section>
    @empty
        <section class="panel"><div class="empty">Belum ada jadwal kelas. Tambahkan jadwal pertama melalui formulir di atas.</div></section>
    @endforelse
@endsection
