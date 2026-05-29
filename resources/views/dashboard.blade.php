@extends('layouts.app')

@section('content')
    <section class="mx-auto max-w-5xl py-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-base-content">{{ __('racklab.dashboard.title') }}</h1>
                <p class="mt-2 text-base text-base-content/70">{{ __('racklab.dashboard.summary') }}</p>
            </div>
            <div class="flex flex-col items-start gap-3 text-sm text-base-content/70 sm:items-end">
                <div>
                    <span class="font-medium text-base-content">{{ __('racklab.dashboard.active_tenant') }}</span>
                    <span>{{ $activeTenant->name }}</span>
                </div>
                <form method="POST" action="{{ route('account.locale.update') }}" class="flex items-center gap-2">
                    @csrf
                    @method('PUT')
                    <label for="locale" class="font-medium text-base-content">{{ __('racklab.account.locale') }}</label>
                    <select dusk="account-locale" id="locale" name="locale" class="select select-bordered select-sm">
                        @foreach (config('racklab.supported_locales', ['en']) as $locale)
                            <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ strtoupper($locale) }}</option>
                        @endforeach
                    </select>
                    <button dusk="save-locale" type="submit" class="btn btn-sm">{{ __('racklab.account.save_locale') }}</button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <div dusk="dashboard-status" class="alert alert-success mt-6">
                {{ session('status') }}
            </div>
        @endif

        <section class="mt-8">
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.dashboard.courses') }}</h2>

            @if ($courses === [])
                <p class="mt-3 text-sm text-base-content/70">{{ __('racklab.dashboard.empty_courses') }}</p>
            @else
                <div class="mt-3 overflow-x-auto rounded border border-base-300">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('racklab.dashboard.course_name') }}</th>
                                <th>{{ __('racklab.dashboard.course_scope') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($courses as $course)
                                <tr>
                                    <td>
                                        <div class="font-medium">
                                            <a href="{{ route('courses.show', ['course' => $course->getKey()]) }}" wire:navigate class="link link-hover" dusk="course-open-{{ $course->getKey() }}">{{ $course->name }}</a>
                                        </div>
                                        <div class="text-xs text-base-content/60">{{ $course->slug }}</div>
                                    </td>
                                    <td>{{ $course->sharing_scope }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.dashboard.projects') }}</h2>

            @if ($projects === [])
                <p class="mt-3 text-sm text-base-content/70">{{ __('racklab.dashboard.empty_projects') }}</p>
            @else
                <div class="mt-3 overflow-x-auto rounded border border-base-300">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('racklab.dashboard.project_name') }}</th>
                                <th>{{ __('racklab.dashboard.project_scope') }}</th>
                                <th>{{ __('racklab.dashboard.project_quota') }}</th>
                                <th class="text-right">{{ __('racklab.dashboard.project_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($projects as $project)
                                @php($projectQuota = $quotaSummaries[$project->getKey()] ?? [])
                                <tr>
                                    <td>
                                        <div class="font-medium">
                                            <a href="{{ route('projects.show', ['project' => $project->getKey()]) }}" wire:navigate class="link link-hover" dusk="project-open-{{ $project->getKey() }}">{{ $project->name }}</a>
                                        </div>
                                        <div class="text-xs text-base-content/60">{{ $project->slug }}</div>
                                    </td>
                                    <td>
                                        @if ($project->is_personal_default)
                                            {{ __('racklab.dashboard.personal_project') }}
                                        @else
                                            {{ $project->sharing_scope }}
                                        @endif
                                    </td>
                                    <td>
                                        @if ($projectQuota === [])
                                            <span class="text-sm text-base-content/60">{{ __('racklab.dashboard.quota_no_limits') }}</span>
                                        @else
                                            <div class="min-w-44 space-y-2">
                                                @foreach ($projectQuota as $quota)
                                                    <div>
                                                        <div class="flex items-center justify-between gap-3 text-xs">
                                                            <span class="font-medium">{{ __($quota['label_key']) }}</span>
                                                            <span>{{ __('racklab.dashboard.quota_usage', ['used' => $quota['used'], 'limit' => $quota['limit']]) }}</span>
                                                        </div>
                                                        <progress class="progress progress-primary h-2" value="{{ $quota['percent'] }}" max="100"></progress>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('deployments.new-vm.store') }}">
                                            @csrf
                                            <input type="hidden" name="project_id" value="{{ $project->getKey() }}">
                                            <button type="submit" dusk="new-vm-{{ $project->getKey() }}" class="btn btn-sm btn-primary">{{ __('racklab.dashboard.new_vm') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.dashboard.api_tokens') }}</h2>

            @if (session('issued_token_authorization_header'))
                <div class="alert alert-success mt-3">
                    <div>
                        <div class="font-medium">{{ __('racklab.dashboard.token_issued', ['name' => session('issued_token_name')]) }}</div>
                        <code dusk="issued-token-header" class="block break-all text-sm">{{ session('issued_token_authorization_header') }}</code>
                    </div>
                </div>
            @endif

            @if ($projects === [])
                <p class="mt-3 text-sm text-base-content/70">{{ __('racklab.dashboard.empty_token_projects') }}</p>
            @else
                <form dusk="api-token-form" method="POST" action="{{ route('account.tokens.store') }}" class="mt-3 grid gap-3 rounded border border-base-300 p-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                    @csrf
                    <label class="form-control">
                        <span class="label-text">{{ __('racklab.dashboard.token_name') }}</span>
                        <input dusk="api-token-name" name="name" value="{{ old('name', 'Dashboard token') }}" class="input input-bordered" maxlength="120" required>
                    </label>
                    <label class="form-control">
                        <span class="label-text">{{ __('racklab.dashboard.token_project') }}</span>
                        <select dusk="api-token-project" name="project_id" class="select select-bordered" required>
                            @foreach ($projects as $project)
                                <option value="{{ $project->getKey() }}" @selected(old('project_id') === $project->getKey())>{{ $project->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex flex-col gap-2">
                        <label class="label cursor-pointer justify-start gap-2">
                            <input type="checkbox" name="abilities[]" value="project.read" class="checkbox checkbox-sm" checked>
                            <span class="label-text">{{ __('racklab.dashboard.ability_project_read') }}</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-2">
                            <input type="checkbox" name="abilities[]" value="deployment.read" class="checkbox checkbox-sm">
                            <span class="label-text">{{ __('racklab.dashboard.ability_deployment_read') }}</span>
                        </label>
                        <button dusk="create-api-token" type="submit" class="btn btn-sm btn-primary">{{ __('racklab.dashboard.create_token') }}</button>
                    </div>
                </form>
            @endif

            @if ($tokenGrants->isEmpty())
                <p class="mt-3 text-sm text-base-content/70">{{ __('racklab.dashboard.empty_tokens') }}</p>
            @else
                <div class="mt-3 overflow-x-auto rounded border border-base-300">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('racklab.dashboard.token_name') }}</th>
                                <th>{{ __('racklab.dashboard.token_abilities') }}</th>
                                <th>{{ __('racklab.dashboard.token_state') }}</th>
                                <th class="text-right">{{ __('racklab.dashboard.token_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tokenGrants as $grant)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $grant->name }}</div>
                                        <div class="text-xs text-base-content/60">{{ $grant->getKey() }}</div>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($grant->abilities as $ability)
                                                <span class="badge badge-outline">{{ $ability }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        @if ($grant->revoked_at)
                                            {{ __('racklab.dashboard.token_revoked_state') }}
                                        @else
                                            {{ __('racklab.dashboard.token_active_state') }}
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if (! $grant->revoked_at)
                                            <form method="POST" action="{{ route('account.tokens.revoke', ['tokenGrant' => $grant->getKey()]) }}">
                                                @csrf
                                                <button dusk="revoke-api-token" type="submit" class="btn btn-sm btn-outline">{{ __('racklab.dashboard.revoke_token') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.dashboard.automation') }}</h2>

            @if ($projects === [])
                <p class="mt-3 text-sm text-base-content/70">{{ __('racklab.dashboard.empty_automation_projects') }}</p>
            @else
                <div class="mt-3 overflow-x-auto rounded border border-base-300">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('racklab.dashboard.project_name') }}</th>
                                <th class="text-right">{{ __('racklab.dashboard.automation_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($projects as $project)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $project->name }}</div>
                                        <div class="text-xs text-base-content/60">{{ $project->slug }}</div>
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <form method="POST" action="{{ route('scripts.fake-runner.store') }}">
                                                @csrf
                                                <input type="hidden" name="project_id" value="{{ $project->getKey() }}">
                                                <input type="hidden" name="runner_kind" value="ansible">
                                                <button dusk="run-ansible" type="submit" class="btn btn-sm btn-outline">{{ __('racklab.dashboard.run_ansible') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('scripts.fake-runner.store') }}">
                                                @csrf
                                                <input type="hidden" name="project_id" value="{{ $project->getKey() }}">
                                                <input type="hidden" name="runner_kind" value="console_script">
                                                <button dusk="run-console" type="submit" class="btn btn-sm btn-outline">{{ __('racklab.dashboard.run_console') }}</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($scriptRuns === [])
                <p class="mt-3 text-sm text-base-content/70">{{ __('racklab.dashboard.empty_script_runs') }}</p>
            @else
                <div class="mt-3 overflow-x-auto rounded border border-base-300">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('racklab.dashboard.script_runner') }}</th>
                                <th>{{ __('racklab.dashboard.script_state') }}</th>
                                <th>{{ __('racklab.dashboard.script_artifacts') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($scriptRuns as $run)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $run['runner_kind'] }}</div>
                                        <div class="text-xs text-base-content/60">{{ $run['id'] }}</div>
                                    </td>
                                    <td>{{ $run['state'] }}</td>
                                    <td>
                                        @if ($run['artifacts'] === [])
                                            <span class="text-sm text-base-content/60">{{ __('racklab.dashboard.no_script_artifacts') }}</span>
                                        @else
                                            <ul class="space-y-1">
                                                @foreach ($run['artifacts'] as $artifact)
                                                    <li>
                                                        <a dusk="script-artifact-{{ $artifact['id'] }}" class="link" href="{{ route('artifacts.show', ['artifact' => $artifact['id']]) }}">
                                                            {{ $artifact['purpose'] }}
                                                        </a>
                                                        <span class="text-xs text-base-content/60">{{ $artifact['id'] }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.dashboard.deployments') }}</h2>

            @if ($labelFilter)
                <div class="mt-2 flex items-center gap-2 text-sm">
                    <span class="badge badge-primary">{{ $labelFilter }}</span>
                    <a href="{{ route('dashboard') }}" wire:navigate class="link">{{ __('racklab.dashboard.clear_label_filter') }}</a>
                </div>
            @endif

            @if ($deployments === [])
                <p class="mt-3 text-sm text-base-content/70">{{ __('racklab.dashboard.empty_deployments') }}</p>
            @else
                <div class="mt-3 overflow-x-auto rounded border border-base-300">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('racklab.dashboard.deployment_name') }}</th>
                                <th>{{ __('racklab.dashboard.deployment_state') }}</th>
                                <th>{{ __('racklab.dashboard.deployment_lease') }}</th>
                                <th>{{ __('racklab.dashboard.deployment_provider') }}</th>
                                <th class="text-right">{{ __('racklab.dashboard.deployment_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($deployments as $deployment)
                                <tr>
                                    <td>
                                        <div class="font-medium">
                                            <a
                                                href="{{ route('deployments.show', ['deployment' => $deployment->getKey()]) }}"
                                                class="link link-hover"
                                                dusk="deployment-open-{{ $deployment->getKey() }}"
                                            >{{ $deployment->name }}</a>
                                        </div>
                                        <div class="text-xs text-base-content/60">{{ $deployment->getKey() }}</div>
                                        <div class="mt-1 flex flex-wrap items-center gap-1">
                                            @foreach (($deployment->labels ?? []) as $label)
                                                <a href="{{ route('dashboard', ['label' => $label]) }}" wire:navigate class="badge badge-sm badge-outline">{{ $label }}</a>
                                            @endforeach
                                        </div>
                                        @if (in_array($deployment->getKey(), $manageableDeploymentIds, true))
                                            <form method="POST" action="{{ route('deployments.labels.update', ['deployment' => $deployment->getKey()]) }}" class="mt-1 flex items-center gap-1">
                                                @csrf
                                                <input
                                                    type="text"
                                                    name="labels"
                                                    value="{{ implode(', ', $deployment->labels ?? []) }}"
                                                    dusk="deployment-labels-{{ $deployment->getKey() }}"
                                                    class="input input-bordered input-xs w-44"
                                                    placeholder="{{ __('racklab.dashboard.labels_placeholder') }}"
                                                    aria-label="{{ __('racklab.dashboard.labels_aria') }}"
                                                >
                                                <button type="submit" dusk="save-labels-{{ $deployment->getKey() }}" class="btn btn-xs">{{ __('racklab.dashboard.labels_save') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                    <td>{{ $deployment->state }}</td>
                                    <td>
                                        @if ($deployment->lease_expires_at)
                                            {{ __('racklab.dashboard.lease_until', ['time' => $deployment->lease_expires_at->toDayDateTimeString()]) }}
                                        @else
                                            <span class="text-base-content/60">{{ __('racklab.dashboard.lease_unlimited') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div>{{ $deployment->provider }}</div>
                                        @foreach ($deployment->resources as $resource)
                                            @foreach ($resource->networkBindings as $binding)
                                                <div class="mt-1 text-xs text-base-content/60">
                                                    <span class="badge badge-outline">{{ $binding->networkOffering->slug }}</span>
                                                    @if ($binding->reachability === 'isolated_no_ingress')
                                                        <span>{{ __('racklab.dashboard.ssh_not_available') }}</span>
                                                    @elseif ($binding->reachability === 'nat_from_management')
                                                        <span>{{ __('racklab.dashboard.ssh_via_nat') }}</span>
                                                    @else
                                                        <span>{{ __('racklab.dashboard.ssh_available') }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        @endforeach
                                    </td>
                                    <td class="text-right">
                                        @if (in_array($deployment->getKey(), $manageableDeploymentIds, true) && ! in_array($deployment->state, ['released', 'expired'], true))
                                            <form method="POST" action="{{ route('deployments.release', ['deployment' => $deployment->getKey()]) }}">
                                                @csrf
                                                <button type="submit" dusk="release-{{ $deployment->getKey() }}" class="btn btn-sm btn-outline">{{ __('racklab.dashboard.release_deployment') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </section>
@endsection
