@extends('layouts.app')

@section('title', 'Komputer dan grup')
@section('section', 'KOMPUTER DAN GRUP')

@section('content')
    <section class="page-heading"><span class="eyebrow">Perangkat</span><h1>Komputer dan grup</h1><p>Komputer baru otomatis disetujui. Di sini Anda dapat mengelompokkan, memantau inventaris, atau menonaktifkan izin perangkat tertentu.</p></section>

    <div class="device-grid">
        @forelse ($computers as $computer)
            @php($inventory = $computer->inventory ?? [])
            <section class="panel device-card {{ $computer->approved ? '' : 'pending-device' }}">
                <div class="panel-heading"><div><span class="computer-dot {{ $computer->last_seen_at?->gte(now()->subSeconds(90)) ? 'online' : '' }}"></span><h2>{{ $computer->computer_name }}</h2><p>{{ $computer->approved ? 'Disetujui' : 'Menunggu persetujuan' }} · Klien {{ $computer->version ?: '-' }}</p></div><span class="{{ $computer->approved ? 'active-badge' : 'warning-badge' }}">{{ $computer->approved ? 'Aktif' : 'Pairing' }}</span></div>
                <dl class="inventory-list">
                    <div><dt>Sistem</dt><dd>{{ $inventory['os'] ?? 'Belum dilaporkan' }}</dd></div>
                    <div><dt>RAM</dt><dd>{{ isset($inventory['ram_gb']) ? $inventory['ram_gb'].' GB' : '-' }}</dd></div>
                    <div><dt>Disk kosong</dt><dd>{{ isset($inventory['disk_free_gb']) ? $inventory['disk_free_gb'].' GB' : '-' }}</dd></div>
                    <div><dt>Roblox Studio</dt><dd>{{ !empty($inventory['roblox_studio']) ? 'Tersedia'.(!empty($inventory['roblox_version']) ? ' · '.$inventory['roblox_version'] : '') : 'Tidak terdeteksi' }}</dd></div>
                    <div><dt>Terakhir terlihat</dt><dd>{{ $computer->last_seen_at?->locale('id')->diffForHumans() ?? '-' }}</dd></div>
                </dl>
                <form method="post" action="{{ route('computers.update', $computer) }}" class="device-form">
                    @csrf @method('PUT')
                    <label>Nama grup<input type="text" name="group_name" value="{{ $computer->group_name }}" placeholder="LAB-A"></label>
                    <label class="check compact-check"><input type="checkbox" name="approved" value="1" @checked($computer->approved)><span><b>Izinkan menerima perintah</b><small>Aktif secara default untuk setiap komputer baru.</small></span></label>
                    <label class="check compact-check"><input type="checkbox" name="reset_pairing" value="1"><span><b>Pairing ulang</b><small>Token baru dibuat saat heartbeat berikutnya.</small></span></label>
                    <button class="button secondary" type="submit">Simpan perangkat</button>
                </form>
            </section>
        @empty
            <section class="panel"><div class="empty">Belum ada komputer yang melakukan pairing.</div></section>
        @endforelse
    </div>
@endsection
