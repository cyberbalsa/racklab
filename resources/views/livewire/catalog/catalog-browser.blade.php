<div class="mx-auto max-w-5xl py-8">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex flex-col gap-2">
            <h1 class="text-2xl font-semibold text-base-content">{{ __('racklab.catalog.title') }}</h1>
            <p class="text-base text-base-content/70">{{ __('racklab.catalog.summary') }}</p>
        </div>
        <a href="{{ route('catalog.publish') }}" wire:navigate class="btn btn-sm btn-outline" dusk="nav-catalog-publish">{{ __('racklab.publish.title') }}</a>
    </div>

    @error('deploy')
        <div dusk="catalog-deploy-error" class="alert alert-error mt-6">{{ $message }}</div>
    @enderror

    @if ($projects === [])
        <div class="alert alert-warning mt-6">{{ __('racklab.catalog.no_projects') }}</div>
    @else
        <label class="form-control mt-6 max-w-sm">
            <span class="label-text">{{ __('racklab.catalog.target_project') }}</span>
            <select dusk="catalog-project" wire:model="selectedProjectId" class="select select-bordered">
                @foreach ($projects as $project)
                    <option value="{{ $project->getKey() }}">{{ $project->name }}</option>
                @endforeach
            </select>
            @error('selectedProjectId')
                <span class="mt-1 text-sm text-error">{{ $message }}</span>
            @enderror
        </label>
    @endif

    @if ($catalogItems === [])
        <p class="mt-8 text-sm text-base-content/70">{{ __('racklab.catalog.empty') }}</p>
    @else
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($catalogItems as $entry)
                @php($item = $entry['item'])
                @php($version = $entry['version'])
                <article
                    dusk="catalog-card-{{ $item->slug }}"
                    class="card border border-base-300 bg-base-200 shadow-sm"
                    wire:key="catalog-{{ $item->getKey() }}"
                >
                    <div class="card-body gap-3">
                        <div class="flex items-start justify-between gap-2">
                            <h2 class="card-title text-base">{{ $item->name }}</h2>
                            <span class="badge badge-outline badge-sm">v{{ $version->version }}</span>
                        </div>

                        @if ($item->description)
                            <p class="text-sm text-base-content/70">{{ $item->description }}</p>
                        @endif

                        @if ($version->summary)
                            <p class="text-xs text-base-content/60">{{ $version->summary }}</p>
                        @endif

                        <div class="card-actions mt-2 justify-end">
                            <button
                                type="button"
                                dusk="catalog-deploy-{{ $item->slug }}"
                                wire:click="deploy('{{ $version->getKey() }}')"
                                wire:loading.attr="disabled"
                                @disabled($projects === [])
                                class="btn btn-primary btn-sm"
                            >
                                {{ __('racklab.catalog.deploy') }}
                            </button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
