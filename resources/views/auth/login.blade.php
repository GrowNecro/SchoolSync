@extends('layouts.auth')

@section('title', 'Masuk')

@section('content')
    <h2>Selamat datang</h2>
    <p>Masuk untuk mengelola sesi pembelajaran.</p>
    @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('login.store') }}" class="stack-form">
        @csrf
        <label>Nama admin<input name="username" value="{{ old('username') }}" autocomplete="username" required autofocus></label>
        <label>Kata sandi<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="button primary wide" type="submit">Masuk ke panel</button>
    </form>
@endsection
