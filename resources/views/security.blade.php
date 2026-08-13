@extends('layouts.app')

@section('title', 'Keamanan')
@section('section', 'KEAMANAN')

@section('content')
    <section class="page-heading"><span class="eyebrow">Akun admin</span><h1>Keamanan panel</h1><p>Perbarui kata sandi yang digunakan untuk masuk ke SchoolSync.</p></section>
    <section class="panel">
        <div class="panel-heading"><div><span class="step">01</span><h2>Ganti kata sandi</h2><p>Tidak ada aturan panjang minimum; konfirmasi harus sama.</p></div></div>
        <form method="post" action="{{ route('password.update') }}" class="form-grid three password-form">@csrf @method('PUT')<label>Kata sandi saat ini<input type="password" name="current_password" required></label><label>Kata sandi baru<input type="password" name="password" required></label><label>Ulangi kata sandi baru<input type="password" name="password_confirmation" required></label><button class="button secondary" type="submit">Perbarui kata sandi</button></form>
    </section>
@endsection
