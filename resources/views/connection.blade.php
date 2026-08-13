@extends('layouts.app')

@section('title', 'Koneksi komputer')
@section('section', 'KONEKSI KOMPUTER')

@section('content')
    <section class="page-heading"><span class="eyebrow">Aplikasi lokal</span><h1>Hubungkan komputer lab</h1><p>Installer menghubungkan komputer langsung ke URL aplikasi Laravel ini tanpa GitHub.</p></section>
    <section class="panel connection">
        <div class="panel-heading"><div><span class="step">01</span><h2>Installer SchoolSync</h2><p>Unduh, lalu jalankan sebagai administrator pada setiap komputer.</p></div><a class="button download-button" href="{{ route('installer') }}">Unduh installer</a></div>
        <div class="endpoint"><code>{{ url('/installer') }}</code><button type="button" data-copy>Salin</button></div>
        <small>File aplikasi berasal dari <code>{{ url('/download') }}</code>. Konfigurasi dan perintah menggunakan endpoint Laravel di bawah <code>/client</code>.</small>
    </section>
@endsection
