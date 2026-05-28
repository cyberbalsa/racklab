<div class="mx-auto max-w-5xl space-y-8 py-8">
    <header class="space-y-1">
        <a href="{{ route('dashboard') }}" wire:navigate class="link link-hover text-sm text-base-content/60">
            {{ __('racklab.projects.back_to_dashboard') }}
        </a>
        <h1 class="text-2xl font-semibold text-base-content">{{ $project->name }}</h1>
        <p class="text-sm text-base-content/60">{{ $project->slug }}</p>
        <p class="text-sm text-base-content/70">
            @if ($project->is_personal_default)
                {{ __('racklab.projects.personal') }}
            @else
                {{ __('racklab.projects.scope', ['scope' => $project->sharing_scope]) }}
            @endif
        </p>
    </header>

    <section>
        <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.projects.quota') }}</h2>
        @if ($quota === [])
            <p class="mt-2 text-sm text-base-content/70">{{ __('racklab.projects.quota_none') }}</p>
        @else
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach ($quota as $dimension)
                    <div class="rounded border border-base-300 p-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ __($dimension['label_key']) }}</span>
                            <span>{{ __('racklab.dashboard.quota_usage', ['used' => $dimension['used'], 'limit' => $dimension['limit']]) }}</span>
                        </div>
                        <progress class="progress progress-primary mt-1 h-2" value="{{ $dimension['percent'] }}" max="100"></progress>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section>
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.projects.deployments') }}</h2>
            <a href="{{ route('catalog') }}" wire:navigate class="btn btn-sm btn-outline">{{ __('racklab.projects.deploy_from_catalog') }}</a>
        </div>
        @if ($deployments === [])
            <p class="mt-2 text-sm text-base-content/70">{{ __('racklab.projects.no_deployments') }}</p>
        @else
            <div class="mt-3 overflow-x-auto rounded border border-base-300">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('racklab.dashboard.deployment_name') }}</th>
                            <th>{{ __('racklab.dashboard.deployment_state') }}</th>
                            <th>{{ __('racklab.dashboard.deployment_provider') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deployments as $deployment)
                            <tr wire:key="dep-{{ $deployment->getKey() }}">
                                <td>
                                    <a href="{{ route('deployments.show', ['deployment' => $deployment->getKey()]) }}" wire:navigate class="link link-hover font-medium">{{ $deployment->name }}</a>
                                    @foreach (($deployment->labels ?? []) as $label)
                                        <span class="badge badge-sm badge-outline">{{ $label }}</span>
                                    @endforeach
                                </td>
                                <td>{{ $deployment->state }}</td>
                                <td>{{ $deployment->provider }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section>
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.projects.stacks') }}</h2>
            <a href="{{ route('stacks.build') }}" wire:navigate class="btn btn-sm btn-outline">{{ __('racklab.projects.build_stack') }}</a>
        </div>
        @if ($stacks === [])
            <p class="mt-2 text-sm text-base-content/70">{{ __('racklab.projects.no_stacks') }}</p>
        @else
            <div class="mt-3 overflow-x-auto rounded border border-base-300">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('racklab.projects.stack_name') }}</th>
                            <th class="text-right">{{ __('racklab.stacks.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stacks as $stack)
                            <tr wire:key="stack-{{ $stack->getKey() }}">
                                <td>
                                    <div class="font-medium">{{ $stack->name }}</div>
                                    <div class="text-xs text-base-content/60">{{ $stack->slug }}</div>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('stacks.export', ['stack' => $stack->getKey()]) }}" class="btn btn-xs btn-outline">{{ __('racklab.stacks.export') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($canReadSshKeys)
        <section>
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.projects.ssh_keys') }}</h2>
            @if ($sshKeys === [])
                <p class="mt-2 text-sm text-base-content/70">{{ __('racklab.projects.no_ssh_keys') }}</p>
            @else
                <div class="mt-3 overflow-x-auto rounded border border-base-300">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('racklab.projects.ssh_key_name') }}</th>
                                <th>{{ __('racklab.projects.ssh_key_type') }}</th>
                                <th>{{ __('racklab.projects.ssh_key_fingerprint') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sshKeys as $key)
                                <tr wire:key="key-{{ $key->getKey() }}">
                                    <td class="font-medium">{{ $key->name }}</td>
                                    <td>{{ $key->key_type }}</td>
                                    <td class="font-mono text-xs">{{ $key->fingerprint }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
