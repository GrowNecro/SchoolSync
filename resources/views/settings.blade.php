@extends('layouts.app')

@section('title', 'Pengaturan kelas')
@section('section', 'PENGATURAN KELAS')

@section('content')
    <section class="page-heading"><span class="eyebrow">Konfigurasi dasar</span><h1>Pengaturan kelas</h1><p>Form ini mengatur Jadwal utama dan pengecualian shutdown global. Gunakan <a class="text-link" href="{{ route('schedules') }}">Multi-jadwal</a> untuk sesi tambahan, target grup, dan mode ujian.</p></section>
    <form method="post" action="{{ route('settings.update') }}" class="config-form">
        @csrf @method('PUT')
        <section class="panel">
            <div class="panel-heading"><div><span class="step">01</span><h2>Sesi kelas</h2><p>Tentukan kapan persiapan kelas dijalankan.</p></div></div>
            <div class="form-grid three">
                <label>Hari pelaksanaan<select name="schedule_day" required>@foreach ($days as $value => $label)<option value="{{ $value }}" @selected(old('schedule_day', $setting->schedule_day) === $value)>{{ $label }}</option>@endforeach</select></label>
                <label>Jam mulai<input type="time" name="start_time" value="{{ old('start_time', substr($setting->start_time, 0, 5)) }}" required></label>
                <label>Jam selesai<input type="time" name="end_time" value="{{ old('end_time', substr($setting->end_time, 0, 5)) }}" required></label>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading"><div><span class="step">02</span><h2>Proyek aktif</h2><p>Pilih proyek Roblox yang disalin ke folder proyek komputer lab.</p></div><a class="text-link" href="{{ route('files') }}">Kelola file</a></div>
            <label>Pilih proyek<select name="project_id"><option value="">Tidak menggunakan proyek</option>@foreach ($robloxProjects as $project)<option value="{{ $project->id }}" @selected((string) old('project_id', $setting->project_id) === (string) $project->id)>{{ $project->filename }}</option>@endforeach</select></label>
        </section>

        <section class="panel">
            <div class="panel-heading"><div><span class="step">03</span><h2>Otomatisasi</h2><p>Atur halaman dan aplikasi yang dibuka saat sesi dimulai.</p></div></div>
            <div class="form-grid two">
                <label>Website atau pencarian yang dibuka <span class="hint">Satu per baris</span><textarea name="browser" rows="6" placeholder="google.com&#10;materi Scratch kelas 8">{{ old('browser', implode("\n", $setting->browser ?? [])) }}</textarea></label>
                <fieldset><legend>Aplikasi yang dijalankan</legend><div class="check-grid">@foreach ($launchers as $value => $label)<label class="check"><input type="checkbox" name="launcher[]" value="{{ $value }}" @checked(in_array($value, old('launcher', $setting->launcher ?? []), true))><span><b>{{ $label }}</b><small>{{ $value }}</small></span></label>@endforeach</div></fieldset>
            </div>
            <div class="shutdown-row">
                <label class="switch-label"><input type="checkbox" name="shutdown_enabled" value="1" @checked(old('shutdown_enabled', $setting->shutdown_enabled))><span class="switch"></span><span><b>Matikan komputer otomatis</b><small>Komputer dimatikan ketika sesi berakhir.</small></span></label>
                <label class="warning-field">Peringatan sebelum selesai <span><input type="number" name="shutdown_warning" min="1" max="120" value="{{ old('shutdown_warning', $setting->shutdown_warning) }}"> menit</span></label>
            </div>
            @php
                $excludedComputers = old('shutdown_excluded_computers', $setting->shutdown_excluded_computers ?? []);
                $knownComputerKeys = $computerNames->map(fn ($name) => mb_strtolower($name))->all();
                $manualExcludedComputers = collect($setting->shutdown_excluded_computers ?? [])
                    ->reject(fn ($name) => in_array(mb_strtolower($name), $knownComputerKeys, true))
                    ->implode("\n");
            @endphp
            <div class="shutdown-exclusions">
                <div>
                    <span class="field-title">Komputer yang tidak boleh dimatikan</span>
                    <small>Nama Windows dicocokkan tanpa membedakan huruf besar dan kecil.</small>
                </div>
                <div class="computer-check-grid">
                    @forelse ($computerNames as $computerName)
                        <label class="check"><input type="checkbox" name="shutdown_excluded_computers[]" value="{{ $computerName }}" @checked(in_array(mb_strtolower($computerName), array_map('mb_strtolower', $excludedComputers), true))><span><b>{{ $computerName }}</b><small>Tetap menyala</small></span></label>
                    @empty
                        <span class="empty-inline">Belum ada komputer terdaftar.</span>
                    @endforelse
                </div>
                <label>Tambahkan nama komputer secara manual <span class="hint">Satu per baris</span><textarea name="shutdown_excluded_manual" rows="3" placeholder="LAB-GURU&#10;SERVER-KELAS">{{ old('shutdown_excluded_manual', $manualExcludedComputers) }}</textarea></label>
            </div>
        </section>
        <div class="save-bar"><span>Perubahan baru diterapkan setelah disimpan.</span><button class="button primary" type="submit">Simpan konfigurasi</button></div>
    </form>
@endsection
