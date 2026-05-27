@extends('layouts.app')

@section('content')
    <section class="mx-auto flex min-h-[calc(100vh-2rem)] max-w-5xl flex-col justify-center gap-8 py-10">
        <div class="max-w-3xl">
            <p class="text-sm font-semibold uppercase tracking-normal text-primary">{{ __('racklab.scaffold.eyebrow') }}</p>
            <h1 class="mt-3 text-4xl font-bold leading-tight text-base-content sm:text-5xl">{{ __('racklab.scaffold.title') }}</h1>
            <p class="mt-5 max-w-2xl text-base leading-7 text-base-content/70">
                {{ __('racklab.scaffold.summary') }}
            </p>
        </div>

        <dl class="grid gap-3 sm:grid-cols-3">
            <div class="border border-base-300 bg-base-200 p-4">
                <dt class="text-sm font-medium text-base-content/70">{{ __('racklab.scaffold.runtime_label') }}</dt>
                <dd class="mt-2 text-lg font-semibold">{{ __('racklab.scaffold.runtime_value') }}</dd>
            </div>
            <div class="border border-base-300 bg-base-200 p-4">
                <dt class="text-sm font-medium text-base-content/70">{{ __('racklab.scaffold.interface_label') }}</dt>
                <dd class="mt-2 text-lg font-semibold">{{ __('racklab.scaffold.interface_value') }}</dd>
            </div>
            <div class="border border-base-300 bg-base-200 p-4">
                <dt class="text-sm font-medium text-base-content/70">{{ __('racklab.scaffold.discipline_label') }}</dt>
                <dd class="mt-2 text-lg font-semibold">{{ __('racklab.scaffold.discipline_value') }}</dd>
            </div>
        </dl>
    </section>
@endsection
