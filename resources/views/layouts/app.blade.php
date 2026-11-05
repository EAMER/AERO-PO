@php
    $workspace = \App\Support\TenantContext::get();
    $section = match (true) {
        request()->routeIs('dashboard') => 'Overview',
        request()->routeIs('requisitions.*', 'procurement.*') => 'Requisitions',
        request()->routeIs('vendors.*') => 'Vendor directory',
        request()->routeIs('reports.*') => 'Reports & insights',
        request()->routeIs('settings.*') => 'Workspace settings',
        request()->routeIs('users.*') => 'Team management',
        default => 'My account',
    };
    $roleLabel = match(auth()->user()->role) {
        \App\Enums\Role::Tlm => 'TLM', \App\Enums\Role::Hamo => 'HAMO', \App\Enums\Role::Cfo => 'CFO',
        default => ucwords(str_replace('_', ' ', auth()->user()->role->value)),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#141e2b">
    <title>{{ $section }} · Aero Procurement</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('aero-contractors-icon.svg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body" x-data="workspaceNavigation" @keydown.escape.window="if(sidebarOpen) closeNavigation()" @resize.window.debounce.150ms="resizeNavigation()" x-effect="document.documentElement.classList.toggle('navigation-open', sidebarOpen)">
    <a class="skip-link" href="#main-content">Skip to content</a>
    @include('layouts.navigation')
    <div class="workspace-shell" x-bind:inert="sidebarOpen && !desktop">
        <header class="topbar">
            <div class="topbar-location">
                <button type="button" x-ref="sidebarToggle" class="icon-button mobile-menu-toggle" @click="openNavigation()" :aria-expanded="sidebarOpen" aria-controls="workspace-navigation" aria-label="Open navigation"><x-icon name="menu" /></button>
                <span class="workspace-name">{{ $workspace?->name ?? 'Company workspace' }}</span>
                <x-icon name="chevron" class="breadcrumb-chevron" />
                <span class="breadcrumb-current">{{ $section }}</span>
            </div>
            <div class="topbar-account">
                <span class="topbar-date">{{ now()->format('D, d M Y') }}</span>
                <a href="{{ route('profile.edit') }}" class="account-link" aria-label="Manage your profile">
                    <span class="avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="account-copy"><strong>{{ auth()->user()->name }}</strong><span>{{ $roleLabel }}</span></span>
                </a>
            </div>
        </header>
        <main id="main-content" class="workspace-main" tabindex="-1">
            @isset($header)
                <div class="page-heading"><p class="eyebrow">PROCUREMENT & STORES</p>{{ $header }}</div>
            @endisset
            <div class="app-content">{{ $slot }}</div>
        </main>
        <footer class="workspace-footer"><span>Aero Procurement <span aria-hidden="true">/</span> {{ $workspace?->name }}</span><span>Every part. Every step. Accounted for.</span></footer>
    </div>
</body>
</html>
