<div class="mx-auto max-w-4xl space-y-6 py-8">
    <header class="space-y-1">
        <a href="{{ route('dashboard') }}" wire:navigate class="link link-hover text-sm text-base-content/60">
            {{ __('racklab.courses.back_to_dashboard') }}
        </a>
        <h1 class="text-2xl font-semibold text-base-content">{{ $course->name }}</h1>
        <p class="text-sm text-base-content/60">{{ $course->slug }}</p>
        @if ($course->description)
            <p class="text-sm text-base-content/70">{{ $course->description }}</p>
        @endif
    </header>

    @if ($canManageRoster)
        <section class="rounded border border-base-300 p-4">
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.courses.import_heading') }}</h2>
            <p class="mt-1 text-sm text-base-content/70">
                {{ $ssoEnabled ? __('racklab.courses.import_hint_sso') : __('racklab.courses.import_hint_local') }}
            </p>

            @if ($importSummary !== null)
                <div dusk="roster-import-summary" class="alert alert-success mt-3 block">
                    <p>{{ __('racklab.courses.import_enrolled', ['count' => $importSummary['enrolled'], 'already' => $importSummary['already']]) }}</p>
                    @if ($importSummary['pending'] !== [])
                        <p class="mt-1 text-sm">{{ __('racklab.courses.import_pending') }}: {{ implode(', ', $importSummary['pending']) }}</p>
                    @endif
                    @if ($importSummary['missing'] !== [])
                        <p class="mt-1 text-sm text-warning">{{ __('racklab.courses.import_missing') }}: {{ implode(', ', $importSummary['missing']) }}</p>
                    @endif
                </div>
            @endif

            <textarea
                dusk="roster-input"
                wire:model="rosterInput"
                rows="4"
                class="textarea textarea-bordered mt-3 w-full font-mono text-sm"
                placeholder="{{ __('racklab.courses.import_placeholder') }}"
            ></textarea>
            <div class="mt-2 flex justify-end">
                <button type="button" dusk="roster-import" wire:click="importRoster" class="btn btn-sm btn-primary">{{ __('racklab.courses.import') }}</button>
            </div>
        </section>
    @endif

    <section>
        <h2 class="text-lg font-semibold text-base-content">
            {{ __('racklab.courses.roster', ['count' => count($members)]) }}
        </h2>
        @if ($members === [])
            <p class="mt-2 text-sm text-base-content/70">{{ __('racklab.courses.no_members') }}</p>
        @else
            <div class="mt-3 overflow-x-auto rounded border border-base-300">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('racklab.courses.member') }}</th>
                            <th>{{ __('racklab.courses.email') }}</th>
                            <th>{{ __('racklab.courses.role') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($members as $member)
                            <tr wire:key="member-{{ $member->getKey() }}">
                                <td class="font-medium">{{ $member->user?->name ?? '—' }}</td>
                                <td class="text-sm text-base-content/70">{{ $member->user?->email ?? '—' }}</td>
                                <td><span class="badge badge-outline">{{ $member->role }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section>
        <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.courses.member_deployments') }}</h2>
        @if ($memberDeployments === [])
            <p class="mt-2 text-sm text-base-content/70">{{ __('racklab.courses.no_member_deployments') }}</p>
        @else
            <div class="mt-3 overflow-x-auto rounded border border-base-300">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('racklab.courses.owner') }}</th>
                            <th>{{ __('racklab.dashboard.deployment_name') }}</th>
                            <th>{{ __('racklab.dashboard.deployment_state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($memberDeployments as $row)
                            <tr wire:key="md-{{ $row['deployment']->getKey() }}">
                                <td class="text-sm">{{ $row['owner'] }}</td>
                                <td>
                                    <a href="{{ route('deployments.show', ['deployment' => $row['deployment']->getKey()]) }}" wire:navigate class="link link-hover font-medium">{{ $row['deployment']->name }}</a>
                                </td>
                                <td>{{ $row['deployment']->state }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
