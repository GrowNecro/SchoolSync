@extends('layouts.app')

@section('title', 'File dari komputer')
@section('section', 'FILE DARI KOMPUTER')

@section('content')
    <section class="page-heading"><span class="eyebrow">Sinkronisasi klien</span><h1>File dari komputer</h1><p>Perubahan di folder kerja komputer dikelompokkan berdasarkan nama komputer dan disimpan privat.</p></section>

    @forelse ($computers as $computer)
        <section class="panel client-file-group">
            <div class="panel-heading"><div><span class="step">PC</span><h2>{{ $computer->computer_name }}</h2><p>{{ $computer->syncedFiles->count() }} file tersinkron · Klien {{ $computer->version ?: '-' }}</p></div></div>
            <div class="file-list">
                @foreach ($computer->syncedFiles as $file)
                    @php($extension = strtoupper(pathinfo($file->relative_path, PATHINFO_EXTENSION) ?: 'FILE'))
                    <article class="client-file-item"><div class="file-icon">{{ substr($extension, 0, 3) }}</div><div><strong>{{ $file->relative_path }}</strong><small>{{ number_format($file->size / 1024, 1) }} KB · {{ $file->synced_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB · {{ $file->versions->count() }} versi</small></div><span class="active-badge">Terbaru</span><a class="text-link" href="{{ route('client-files.download', $file) }}">Unduh</a>
                        <details class="version-history"><summary>Lihat riwayat versi</summary><div>
                            @foreach ($file->versions as $version)
                                <div class="version-row"><span><b>{{ $version->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i:s') }}</b><small>{{ number_format($version->size / 1024, 1) }} KB · {{ substr($version->sha256, 0, 12) }}</small></span><a class="text-link" href="{{ route('client-file-versions.download', $version) }}">Unduh</a>@if ($version->sha256 !== $file->sha256)<form method="post" action="{{ route('client-file-versions.restore', $version) }}" onsubmit="return confirm('Pulihkan versi ini ke komputer {{ $computer->computer_name }}?')">@csrf<button class="restore-button" type="submit">Pulihkan</button></form>@else<span class="active-badge">Aktif</span>@endif</div>
                            @endforeach
                        </div></details>
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <section class="panel"><div class="empty">Belum ada file yang diunggah oleh komputer klien.</div></section>
    @endforelse
@endsection
