<x-guest-layout>
    <p class="eyebrow">YOUR WORKSPACE AWAITS</p>
    <h2 class="signin-title">Welcome back.</h2>
    <p class="signin-description">Sign in to manage your procurement operations.</p>
    <x-auth-session-status class="mb-5" :status="session('status')" />
    <form method="POST" action="{{ route('login') }}" class="signin-form">
        @csrf
        <div>
            <x-input-label for="email" value="Work email" />
            <x-text-input id="email" class="mt-2 block w-full" type="email" name="email" :value="old('email')" placeholder="you@your-company.com" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div x-data="{ showPassword: false }">
            <x-input-label for="password" value="Password" />
            <div class="password-field">
                <x-text-input id="password" class="mt-2 block w-full" type="password" x-bind:type="showPassword ? 'text' : 'password'" name="password" placeholder="Enter your password" required autocomplete="current-password" />
                <button type="button" @click="showPassword = !showPassword" :aria-pressed="showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'" x-text="showPassword ? 'Hide' : 'Show'">Show</button>
            </div>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div class="signin-options">
            <label for="remember_me"><input id="remember_me" type="checkbox" name="remember" class="brand-checkbox"> Keep me signed in</label>
            @if(Route::has('password.request'))<a href="{{ route('password.request') }}">Forgot password?</a>@endif
        </div>
        <x-primary-button class="signin-submit">Sign in to workspace <x-icon name="arrow" /></x-primary-button>
    </form>
    <div class="signin-help"><x-icon name="users" /><p>Need access or a password reset?<br><strong>Contact your company administrator.</strong></p></div>
</x-guest-layout>
