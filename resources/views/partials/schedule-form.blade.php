@php
    $isEdit = isset($schedule) && $schedule;
    $currentTarget = $isEdit && $schedule->target_type !== 'all'
        ? $schedule->target_type.':'.(($schedule->target_value ?? [])[0] ?? '')
        : 'all';
    $availableTargetValues = collect($commandTargets)->pluck('value');
@endphp
<form method="post" action="{{ $isEdit ? route('schedules.update', $schedule) : route('schedules.store') }}" class="schedule-form">
    @csrf @if ($isEdit) @method('PUT') @endif
    <div class="form-grid three">
        <label>Nama jadwal<input type="text" name="name" value="{{ $isEdit ? $schedule->name : '' }}" placeholder="Kelas 8A" required></label>
        <label>Hari<select name="schedule_day" required>@foreach ($days as $value => $label)<option value="{{ $value }}" @selected($isEdit && $schedule->schedule_day === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Target<select name="target" required>@if (!$availableTargetValues->contains($currentTarget))<option value="{{ $currentTarget }}">Target tersimpan · {{ $currentTarget }}</option>@endif @foreach ($commandTargets as $target)<option value="{{ $target['value'] }}" @selected($currentTarget === $target['value'])>{{ $target['label'] }}</option>@endforeach</select></label>
        <label>Jam mulai<input type="time" name="start_time" value="{{ $isEdit ? substr($schedule->start_time, 0, 5) : '08:00' }}" required></label>
        <label>Jam selesai<input type="time" name="end_time" value="{{ $isEdit ? substr($schedule->end_time, 0, 5) : '10:00' }}" required></label>
        <label>Proyek aktif<select name="project_id"><option value="">Tanpa proyek</option>@foreach ($projects as $project)<option value="{{ $project->id }}" @selected($isEdit && $schedule->project_id === $project->id)>{{ $project->filename }}</option>@endforeach</select></label>
    </div>
    <div class="form-grid two">
        <label>Website atau pencarian <span class="hint">Satu per baris</span><textarea name="browser" rows="5" placeholder="classroom.google.com">{{ $isEdit ? implode("\n", $schedule->browser ?? []) : '' }}</textarea></label>
        <fieldset><legend>Aplikasi yang dijalankan</legend><div class="check-grid">@foreach ($launchers as $value => $label)<label class="check"><input type="checkbox" name="launcher[]" value="{{ $value }}" @checked($isEdit && in_array($value, $schedule->launcher ?? [], true))><span><b>{{ $label }}</b><small>{{ $value }}</small></span></label>@endforeach</div></fieldset>
    </div>
    <div class="schedule-options">
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(!$isEdit || $schedule->enabled)><span><b>Jadwal aktif</b><small>Klien akan menjalankan sesi ini.</small></span></label>
        <label class="check"><input type="checkbox" name="shutdown_enabled" value="1" @checked($isEdit && $schedule->shutdown_enabled)><span><b>Shutdown setelah sesi</b><small>Daftar pengecualian global tetap berlaku.</small></span></label>
        <label>Peringatan shutdown<input type="number" name="shutdown_warning" min="1" max="120" value="{{ $isEdit ? $schedule->shutdown_warning : 10 }}" required></label>
    </div>
    <div class="exam-box">
        <label class="switch-label"><input type="checkbox" name="exam_enabled" value="1" @checked($isEdit && $schedule->exam_enabled)><span class="switch"></span><span><b>Mode ujian</b><small>Tutup aplikasi terlarang selama jadwal berlangsung.</small></span></label>
        <label>Nama proses yang diblokir <span class="hint">Satu per baris, tanpa .exe juga boleh</span><textarea name="blocked_processes" rows="3" placeholder="discord&#10;steam&#10;chrome">{{ $isEdit ? implode("\n", $schedule->blocked_processes ?? []) : '' }}</textarea></label>
    </div>
    <button class="button primary" type="submit">{{ $isEdit ? 'Simpan jadwal' : 'Tambahkan jadwal' }}</button>
</form>
