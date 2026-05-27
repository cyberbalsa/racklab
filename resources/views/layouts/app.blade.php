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
    <main class="container mx-auto p-4">
        {{ $slot ?? '' }}
        @yield('content')
    </main>
    @livewireScripts
    @stack('scripts')
</body>
</html>
