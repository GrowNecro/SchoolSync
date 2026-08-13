@extends('layouts.app')

@section('title', 'Riwayat perintah')
@section('section', 'RIWAYAT PERINTAH')

@section('content')
    <section class="page-heading"><span class="eyebrow">Aktivitas</span><h1>Riwayat perintah</h1><p>Status penerimaan dan hasil eksekusi dari setiap komputer target.</p></section>

    @forelse ($commands as $command)
        <section class="panel command-card">
            <div class="panel-heading"><div><span class="step">{{ $command->id }}</span><h2>{{ $command->action }}</h2><p>Target {{ $command->target_type }} · {{ $command->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i:s') }} WIB</p></div></div>
            <div class="execution-list">
                @forelse ($command->executions as $execution)
                    <article><div><strong>{{ $execution->computer?->computer_name ?? 'Komputer dihapus' }}</strong><small>{{ $execution->message ?: 'Belum ada pesan dari klien.' }}</small></div><span class="execution-status status-{{ $execution->status }}">{{ $execution->status }}</span></article>
                @empty
                    <div class="empty">Tidak ada komputer yang cocok dengan target saat perintah dibuat.</div>
                @endforelse
            </div>
        </section>
    @empty
        <section class="panel"><div class="empty">Belum ada perintah.</div></section>
    @endforelse
@endsection
