<div class="mx-auto max-w-4xl py-8">
    <div class="flex flex-col gap-2">
        <h1 class="text-2xl font-semibold text-base-content">{{ __('racklab.stacks.title') }}</h1>
        <p class="text-base text-base-content/70">{{ __('racklab.stacks.summary') }}</p>
    </div>

    @error('save')
        <div dusk="stack-save-error" class="alert alert-error mt-6">{{ $message }}</div>
    @enderror

    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <label class="form-control">
            <span class="label-text">{{ __('racklab.stacks.field_name') }}</span>
            <input dusk="stack-name" type="text" wire:model="stackName" maxlength="120" class="input input-bordered" placeholder="{{ __('racklab.stacks.name_placeholder') }}">
            @error('stackName')
                <span class="mt-1 text-sm text-error">{{ $message }}</span>
            @enderror
        </label>

        <label class="form-control">
            <span class="label-text">{{ __('racklab.stacks.field_project') }}</span>
            <select dusk="stack-project" wire:model="selectedProjectId" class="select select-bordered">
                @foreach ($projects as $project)
                    <option value="{{ $project->getKey() }}">{{ $project->name }}</option>
                @endforeach
            </select>
            @error('selectedProjectId')
                <span class="mt-1 text-sm text-error">{{ $message }}</span>
            @enderror
        </label>
    </div>

    <section class="mt-6">
        <h2 class="text-sm font-semibold text-base-content/80">{{ __('racklab.stacks.available_networks') }}</h2>
        @if ($offerings === [])
            <p class="mt-1 text-xs text-base-content/60">{{ __('racklab.stacks.no_offerings') }}</p>
        @else
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach ($offerings as $offering)
                    <li>
                        <span class="badge badge-ghost gap-1" title="{{ $offering->reachability }}">{{ $offering->name }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="mt-6 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.stacks.vms') }}</h2>
        <button type="button" dusk="add-vm" wire:click="addVm" class="btn btn-sm btn-outline">{{ __('racklab.stacks.add_vm') }}</button>
    </div>

    @error('vms')
        <div class="alert alert-warning mt-3">{{ $message }}</div>
    @enderror

    @if ($vms === [])
        <p class="mt-3 text-sm text-base-content/70">{{ __('racklab.stacks.no_vms') }}</p>
    @else
        <div class="mt-3 space-y-4">
            @foreach ($vms as $index => $vm)
                <div class="card border border-base-300 bg-base-200" wire:key="vm-{{ $index }}">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ $vm['key'] }}</span>
                            <button type="button" dusk="remove-vm-{{ $index }}" wire:click="removeVm({{ $index }})" class="btn btn-xs btn-ghost text-error">{{ __('racklab.stacks.remove') }}</button>
                        </div>

                        <div>
                            <span class="text-sm font-medium text-base-content/80">{{ __('racklab.stacks.networks') }}</span>
                            @if ($vm['networks'] === [])
                                <p class="text-xs text-base-content/60">{{ __('racklab.stacks.no_networks') }}</p>
                            @else
                                <ul class="mt-1 flex flex-wrap gap-2">
                                    @foreach ($vm['networks'] as $nicIndex => $nic)
                                        <li>
                                            <span class="badge badge-outline gap-1">
                                                {{ $nic['key'] }} → {{ $nic['offering_slug'] }}
                                                <button type="button" wire:click="detachNetwork({{ $index }}, {{ $nicIndex }})" class="text-error">✕</button>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($offerings === [])
                                <p class="mt-2 text-xs text-base-content/60">{{ __('racklab.stacks.no_offerings') }}</p>
                            @else
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($offerings as $offering)
                                        <button
                                            type="button"
                                            dusk="attach-{{ $index }}-{{ $offering->slug }}"
                                            wire:click="attachNetwork({{ $index }}, '{{ $offering->slug }}')"
                                            class="btn btn-xs btn-outline"
                                        >+ {{ $offering->name }}</button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-8 flex justify-end">
        <button type="button" dusk="save-stack" wire:click="save" class="btn btn-primary">{{ __('racklab.stacks.save') }}</button>
    </div>
</div>
