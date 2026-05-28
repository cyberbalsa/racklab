@extends('layouts.app')

@section('content')
    <section class="mx-auto max-w-5xl space-y-6 py-8" data-testid="deployment-detail">
        <header class="space-y-1">
            <a href="{{ route('dashboard') }}" class="link link-hover text-sm text-base-content/60">
                {{ __('racklab.deployments.show.back_to_dashboard') }}
            </a>
            <h1 class="text-2xl font-bold" data-testid="deployment-name">
                {{ $deployment->name }}
            </h1>
            <p class="text-sm text-base-content/70" data-testid="deployment-id">{{ $deployment->getKey() }}</p>
            <p class="text-sm" data-testid="deployment-state">
                {{ __('racklab.deployments.show.state_label') }}: <span class="font-mono">{{ $deployment->state }}</span>
            </p>
            <p class="text-sm" data-testid="deployment-provider">
                {{ __('racklab.deployments.show.provider_label') }}: <span class="font-mono">{{ $deployment->provider }}</span>
            </p>
        </header>

        @livewire('console.deployment-console-pane', ['deployment' => $deployment, 'consoleKind' => $consoleKind])

        @livewire('vpnaas.deployment-vpn-panel', ['deployment' => $deployment])
    </section>
@endsection
