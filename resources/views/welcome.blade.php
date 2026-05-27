@extends('layouts.app')

@section('content')
    <section class="mx-auto flex min-h-[calc(100vh-2rem)] max-w-5xl flex-col justify-center gap-8 py-10">
        <div class="max-w-3xl">
            <p class="text-sm font-semibold uppercase tracking-normal text-primary">RackLab scaffold</p>
            <h1 class="mt-3 text-4xl font-bold leading-tight text-base-content sm:text-5xl">Self-service lab infrastructure</h1>
            <p class="mt-5 max-w-2xl text-base leading-7 text-base-content/70">
                This Laravel 13 foundation is wired for Octane, Livewire, Filament, Tailwind, and the RackLab PRD-driven test gates.
            </p>
        </div>

        <dl class="grid gap-3 sm:grid-cols-3">
            <div class="border border-base-300 bg-base-200 p-4">
                <dt class="text-sm font-medium text-base-content/70">Runtime</dt>
                <dd class="mt-2 text-lg font-semibold">Octane + FrankenPHP</dd>
            </div>
            <div class="border border-base-300 bg-base-200 p-4">
                <dt class="text-sm font-medium text-base-content/70">Interface</dt>
                <dd class="mt-2 text-lg font-semibold">Livewire + Filament</dd>
            </div>
            <div class="border border-base-300 bg-base-200 p-4">
                <dt class="text-sm font-medium text-base-content/70">Discipline</dt>
                <dd class="mt-2 text-lg font-semibold">Pint + Larastan + tests</dd>
            </div>
        </dl>
    </section>
@endsection
