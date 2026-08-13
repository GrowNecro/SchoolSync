@extends('layouts.app')

@section('title', 'File dan proyek')
@section('section', 'FILE DAN PROYEK')

@section('content')
    <section class="page-heading"><span class="eyebrow">Sinkronisasi</span><h1>Perpustakaan file</h1><p>Semua file disimpan privat di server dan disinkronkan ke komputer lab.</p></section>
    <section class="panel">
        <div class="panel-heading"><div><span class="step">01</span><h2>Unggah file</h2><p>Gambar, dokumen, video, ZIP, proyek, dan jenis file lain didukung.</p></div></div>
        <form method="post" action="{{ route('projects.store') }}" enctype="multipart/form-data" class="upload-box">@csrf<label><b>Taruh file di sini</b><span>Maksimal 100 MB per file. ZIP diekstrak otomatis di komputer lab.</span><input type="file" name="project_file" required></label><button class="button secondary" type="submit">Unggah file</button></form>
        <div class="file-list">
            @forelse ($projects as $project)
                @php($extension = strtoupper(pathinfo($project->filename, PATHINFO_EXTENSION) ?: 'FILE'))
                <article><div class="file-icon">{{ substr($extension, 0, 3) }}</div><div><strong>{{ $project->filename }}</strong><small>{{ number_format($project->size / 1024, 1) }} KB · {{ $project->updated_at->format('d M Y, H:i') }}{{ $project->extract ? ' · Ekstrak otomatis' : '' }}</small></div><span class="{{ $setting->project_id === $project->id ? 'active-badge' : 'muted-badge' }}">{{ $setting->project_id === $project->id ? 'Proyek aktif' : ($project->extract ? 'ZIP' : 'Tersimpan') }}</span><form method="post" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm('Hapus file ini dari server?')">@csrf @method('DELETE')<button class="delete-button" type="submit">Hapus</button></form></article>
            @empty <div class="empty">Belum ada file yang diunggah.</div> @endforelse
        </div>
    </section>
@endsection
