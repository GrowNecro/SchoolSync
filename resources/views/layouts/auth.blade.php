<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title') · SchoolSync Control</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-view">
<main class="auth-shell">
    <section class="auth-brand">
        <a class="brand" href="{{ url('/') }}"><span class="brand-mark">S</span><span>SchoolSync</span></a>
        <div class="auth-copy">
            <span class="eyebrow">Kontrol laboratorium</span>
            <h1>Siapkan kelas dari satu tempat.</h1>
            <p>Atur jadwal, proyek, website, dan aplikasi yang dibuka di seluruh komputer laboratorium.</p>
        </div>
        <div class="signal-card"><span class="signal-dot"></span><span>Panel aktif · Laravel + MySQL</span></div>
    </section>
    <section class="auth-panel">
        <div class="auth-form-wrap">
            <span class="mobile-brand">SchoolSync Control</span>
            @yield('content')
            <small>Gunakan HTTPS saat panel sudah berada di hosting.</small>
        </div>
    </section>
</main>
</body>
</html>
