@extends('layouts.app')

@section('title', 'Multi-jadwal')
@section('section', 'MULTI-JADWAL')

@section('content')
    <section class="page-heading"><span class="eyebrow">Otomatisasi</span><h1>Multi-jadwal kelas</h1><p>Setiap sesi dapat memiliki target, proyek, aplikasi, website, shutdown, dan aturan ujian sendiri.</p></section>

    <section class="panel">
        <div class="panel-heading"><div><span class="step">NEW</span><h2>Tambah jadwal</h2><p>Buat sesi baru untuk kelas atau kelompok komputer tertentu.</p></div></div>
        @include('partials.schedule-form', ['schedule' => null])
    </section>

    @foreach ($schedules as $schedule)
        <section class="panel schedule-card">
            <div class="panel-heading"><div><span class="step">{{ $loop->iteration }}</span><h2>{{ $schedule->name }}</h2><p>{{ $days[$schedule->schedule_day] ?? $schedule->schedule_day }} · {{ substr($schedule->start_time, 0, 5) }}—{{ substr($schedule->end_time, 0, 5) }} · {{ $schedule->enabled ? 'Aktif' : 'Nonaktif' }}</p></div>@if ($schedule->exam_enabled)<span class="warning-badge">Mode ujian</span>@endif</div>
            <details><summary>Edit jadwal</summary>@include('partials.schedule-form', ['schedule' => $schedule])</details>
            <form method="post" action="{{ route('schedules.destroy', $schedule) }}" class="inline-delete" onsubmit="return confirm('Hapus jadwal {{ $schedule->name }}?')">@csrf @method('DELETE')<button class="delete-button" type="submit">Hapus jadwal</button></form>
        </section>
    @endforeach
@endsection
