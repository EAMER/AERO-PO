@php
    $navRole = auth()->user()->role;
    $navLinks = [
        ['route' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Overview', 'icon' => 'dashboard'],
        ['route' => 'requisitions.index', 'pattern' => 'requisitions.*', 'label' => 'Requisitions', 'icon' => 'clipboard'],
    ];
    if ($navRole !== \App\Enums\Role::Requester) { $navLinks[] = ['route' => 'vendors.index', 'pattern' => 'vendors.*', 'label' => 'Vendors & carriers', 'icon' => 'vendors']; }
    if (!in_array($navRole, [\App\Enums\Role::Requester, \App\Enums\Role::StoreStaff, \App\Enums\Role::StoreSupervisor], true)) { $navLinks[] = ['route' => 'reports.index', 'pattern' => 'reports.*', 'label' => 'Reports & insights', 'icon' => 'chart']; }
@endphp
<button type="button" x-cloak x-show="sidebarOpen" x-transition.opacity class="sidebar-overlay" @click="closeNavigation()" aria-label="Close navigation" tabindex="-1"></button>
<aside id="workspace-navigation" x-ref="sidebarPanel" class="sidebar" :class="{ 'sidebar-open': sidebarOpen }" x-bind:inert="!desktop && !sidebarOpen" :aria-hidden="!desktop && !sidebarOpen ? 'true' : null" @keydown.tab="trapNavigationFocus($event)">
    <div class="sidebar-brand">
        <a href="{{ route('dashboard') }}" aria-label="Aero Contractors procurement home"><x-application-logo /></a>
        <button type="button" x-ref="sidebarClose" class="icon-button sidebar-close" @click="closeNavigation()" aria-label="Close navigation"><x-icon name="close" /></button>
        <span class="brand-caption">PROCUREMENT WORKSPACE</span>
    </div>
    <div class="sidebar-company"><span class="company-mark">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($workspace?->name ?? 'A', 0, 1)) }}</span><span><strong>{{ $workspace?->name ?? 'Company workspace' }}</strong><small>Company workspace</small></span><x-icon name="shield" /></div>
    <nav aria-label="Main navigation" class="sidebar-nav">
        <p class="nav-label">WORKSPACE</p>
        @foreach($navLinks as $link)
            <a href="{{ route($link['route']) }}" class="sidebar-link {{ request()->routeIs($link['pattern']) ? 'is-active' : '' }}" @if(request()->routeIs($link['pattern'])) aria-current="page" @endif><x-icon :name="$link['icon']" /><span>{{ $link['label'] }}</span>@if(request()->routeIs($link['pattern']))<span class="active-indicator"></span>@endif</a>
        @endforeach
        @if($navRole->isAdmin() || $navRole === \App\Enums\Role::Tlm)
            <p class="nav-label nav-label-secondary">MANAGEMENT</p>
            @if($navRole->isAdmin())<a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'is-active' : '' }}" @if(request()->routeIs('users.*')) aria-current="page" @endif><x-icon name="users" /><span>Team & access</span></a>@endif
            <a href="{{ route('settings.index') }}" class="sidebar-link {{ request()->routeIs('settings.*') ? 'is-active' : '' }}" @if(request()->routeIs('settings.*')) aria-current="page" @endif><x-icon name="settings" /><span>Settings</span></a>
        @endif
    </nav>
    <div class="sidebar-bottom">
        <div class="stock-boundary"><span class="connection-dot"></span><span><strong>DigiMaint stock records</strong><small>Verified manual handovers</small></span></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="sidebar-link logout-button"><x-icon name="logout" /><span>Sign out</span></button></form>
        <p class="sidebar-copyright">© {{ now()->year }} Aero Contractors</p>
    </div>
</aside>
