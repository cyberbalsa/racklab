<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'RackLab') }}</title>
    @vite(['resources/css/app.css', 'resources/js/bootstrap.ts'])
    @livewireStyles
</head>
<body class="min-h-screen bg-base-100 text-base-content">
    <header class="border-b border-base-300 bg-base-200">
        <nav class="navbar container mx-auto px-4" aria-label="{{ __('racklab.nav.primary') }}">
            <div class="flex-1">
                <a
                    href="{{ auth()->check() ? route('dashboard') : url('/') }}"
                    class="btn btn-ghost px-2 text-lg font-semibold normal-case"
                    wire:navigate
                >RackLab</a>
            </div>
            <div class="flex flex-none items-center gap-1">
                @auth
                    <ul class="menu menu-horizontal hidden gap-1 px-1 sm:flex">
                        <li><a href="{{ route('dashboard') }}" wire:navigate dusk="navbar-dashboard">{{ __('racklab.dashboard.title') }}</a></li>
                        <li><a href="{{ route('catalog') }}" wire:navigate dusk="navbar-catalog">{{ __('racklab.catalog.nav') }}</a></li>
                        <li><a href="{{ route('docs.index') }}" wire:navigate dusk="navbar-docs">{{ __('racklab.docs.nav') }}</a></li>
                    </ul>
                    <form method="POST" action="{{ route('logout') }}" class="ml-1">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline" dusk="navbar-logout">{{ __('racklab.auth.logout') }}</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="btn btn-sm btn-ghost" dusk="navbar-login">{{ __('racklab.auth.login') }}</a>
                    <a href="{{ route('register') }}" class="btn btn-sm btn-primary" dusk="navbar-register">{{ __('racklab.auth.register') }}</a>
                @endauth
            </div>
        </nav>
    </header>
    <main class="container mx-auto p-4">
        {{ $slot ?? '' }}
        @yield('content')
    </main>
    @livewireScripts
    @stack('scripts')
</body>
</html>
