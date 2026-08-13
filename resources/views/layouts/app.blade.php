<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'SchoolSync') · SchoolSync Control</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-view">
<div class="layout">
    <aside class="sidebar">
        <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">S</span><span>SchoolSync</span></a>
        <span class="sidebar-label">Control center</span>
        <nav>
            <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Dashboard</a>
            <a class="{{ request()->routeIs('settings*') ? 'active' : '' }}" href="{{ route('settings') }}">Pengaturan kelas</a>
            <a class="{{ request()->routeIs('files') ? 'active' : '' }}" href="{{ route('files') }}">File &amp; proyek</a>
            <a class="{{ request()->routeIs('client-files*') ? 'active' : '' }}" href="{{ route('client-files') }}">File dari komputer</a>
            <a class="{{ request()->routeIs('connection') ? 'active' : '' }}" href="{{ route('connection') }}">Koneksi komputer</a>
            <a class="{{ request()->routeIs('security') ? 'active' : '' }}" href="{{ route('security') }}">Keamanan</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-status"><i></i><span><strong>Sistem aktif</strong><small>Terhubung ke panel</small></span></div>
            <form method="post" action="{{ route('logout') }}" class="logout-form">@csrf<button type="submit">Keluar dari panel</button></form>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <button class="menu-button" type="button" aria-label="Buka menu" data-menu>☰</button>
            <div class="topbar-title"><span class="breadcrumb">SCHOOLSYNC</span><b>@yield('section', 'DASHBOARD')</b></div>
            <div class="admin-chip"><span>{{ strtoupper(substr(auth()->user()->username, 0, 1)) }}</span>{{ auth()->user()->username }}</div>
        </header>

        <div class="content">
            @if (session('success'))<div class="alert success">{{ session('success') }}</div>@endif
            @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
            @yield('content')
        </div>
        <footer>SchoolSync Control · {{ date('Y') }}</footer>
    </main>
</div>
</body>
</html>
