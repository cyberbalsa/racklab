<div class="mx-auto max-w-5xl space-y-6 py-8">
    <header class="space-y-1">
        <a href="{{ route('projects.show', ['project' => $project->getKey()]) }}" wire:navigate class="link link-hover text-sm text-base-content/60">
            {{ __('racklab.scripts_lib.back_to_project') }}
        </a>
        <h1 class="text-2xl font-semibold text-base-content">{{ __('racklab.scripts_lib.title') }}</h1>
        <p class="text-sm text-base-content/70">{{ $project->name }}</p>
    </header>

    @if (session('status'))
        <div dusk="script-status" class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (! $canViewScripts)
        <div class="alert alert-warning" role="status">{{ __('racklab.scripts_lib.no_access') }}</div>
    @elseif ($scripts === [])
        <p class="text-sm text-base-content/70">{{ __('racklab.scripts_lib.empty') }}</p>
    @else
        <div class="overflow-x-auto rounded border border-base-300">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('racklab.scripts_lib.name') }}</th>
                        <th>{{ __('racklab.scripts_lib.runner') }}</th>
                        <th>{{ __('racklab.scripts_lib.version') }}</th>
                        <th>{{ __('racklab.scripts_lib.state') }}</th>
                        <th>{{ __('racklab.scripts_lib.approval') }}</th>
                        @if ($canApprove)
                            <th class="text-right">{{ __('racklab.scripts_lib.actions') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scripts as $row)
                        @php($script = $row['model'])
                        <tr wire:key="script-{{ $script->getKey() }}">
                            <td>
                                <div class="font-medium">{{ $script->name }}</div>
                                <div class="text-xs text-base-content/60">{{ $script->slug }}</div>
                            </td>
                            <td><span class="badge badge-outline">{{ $script->runner_kind }}</span></td>
                            <td>{{ $row['version'] === null ? '—' : 'v'.$row['version'] }}</td>
                            <td>{{ $script->state }}</td>
                            <td>
                                @if ($row['approved'])
                                    <span class="badge badge-success">{{ __('racklab.scripts_lib.approved') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('racklab.scripts_lib.not_approved') }}</span>
                                @endif
                            </td>
                            @if ($canApprove)
                                <td class="text-right">
                                    @if ($row['approved'])
                                        <button type="button" wire:click="revoke('{{ $script->getKey() }}')" dusk="revoke-{{ $script->slug }}" class="btn btn-xs btn-outline">{{ __('racklab.scripts_lib.revoke') }}</button>
                                    @else
                                        <button type="button" wire:click="approve('{{ $script->getKey() }}')" dusk="approve-{{ $script->slug }}" class="btn btn-xs btn-primary">{{ __('racklab.scripts_lib.approve') }}</button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
