<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#141e2b">
    <title>Sign in · Aero Procurement</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('aero-contractors-icon.svg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="guest-body">
    <main class="signin-layout">
        <section class="signin-brand" aria-label="Aero Contractors procurement">
            <a href="{{ route('login') }}" class="signin-logo"><x-application-logo /></a>
            <div class="signin-story">
                <span class="signin-kicker"><span></span> PROCUREMENT & STORES</span>
                <h1>Keeping every part<br>of the operation<br><em>moving.</em></h1>
                <p>One workspace for your people, parts and purchasing. From the first request to the final receipt.</p>
                <div class="signin-process" aria-label="Procurement process"><span><x-icon name="clipboard" /> Request</span><x-icon name="chevron" /><span><x-icon name="shield" /> Approve</span><x-icon name="chevron" /><span><x-icon name="box" /> Receive</span></div>
            </div>
            <div class="signin-brand-footer"><span>Aero Contractors</span><span>Procurement workspace</span></div>
            <img src="{{ asset('aero-contractors-icon.svg') }}" class="signin-watermark" alt="" aria-hidden="true">
        </section>
        <section class="signin-form-side">
            <span class="signin-workspace"><x-icon name="shield" /> {{ \App\Support\TenantContext::get()?->name ?? 'Company workspace' }}</span>
            <div class="signin-card">{{ $slot }}</div>
            <p class="signin-footer">© {{ now()->year }} Aero Contractors <span>·</span> Authorized personnel only</p>
        </section>
    </main>
</body>
</html>
