<div class="mx-auto max-w-4xl space-y-8 py-8">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-base-content">{{ __('racklab.publish.title') }}</h1>
        <p class="text-base text-base-content/70">{{ __('racklab.publish.summary') }}</p>
    </header>

    @if (session('status'))
        <div dusk="publish-status" class="alert alert-success">{{ session('status') }}</div>
    @endif

    @error('publish')
        <div class="alert alert-error">{{ $message }}</div>
    @enderror

    @if ($stacks === [])
        <div class="alert alert-warning" role="status">{{ __('racklab.publish.no_stacks') }}</div>
    @else
        <section class="rounded border border-base-300 p-4">
            <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.publish.publish_heading') }}</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <label class="form-control sm:col-span-1">
                    <span class="label-text">{{ __('racklab.publish.field_stack') }}</span>
                    <select dusk="publish-stack" wire:model="selectedStackId" class="select select-bordered">
                        <option value="">{{ __('racklab.publish.choose_stack') }}</option>
                        @foreach ($stacks as $stack)
                            <option value="{{ $stack->getKey() }}">{{ $stack->name }}</option>
                        @endforeach
                    </select>
                    @error('selectedStackId')
                        <span class="mt-1 text-sm text-error">{{ $message }}</span>
                    @enderror
                </label>
                <label class="form-control sm:col-span-1">
                    <span class="label-text">{{ __('racklab.publish.field_name') }}</span>
                    <input dusk="publish-name" type="text" wire:model="itemName" maxlength="120" class="input input-bordered">
                    @error('itemName')
                        <span class="mt-1 text-sm text-error">{{ $message }}</span>
                    @enderror
                </label>
                <label class="form-control sm:col-span-1">
                    <span class="label-text">{{ __('racklab.publish.field_version') }}</span>
                    <input dusk="publish-version" type="text" wire:model="versionLabel" maxlength="60" class="input input-bordered">
                    @error('versionLabel')
                        <span class="mt-1 text-sm text-error">{{ $message }}</span>
                    @enderror
                </label>
            </div>
            <div class="mt-3 flex justify-end">
                <button type="button" dusk="publish-submit" wire:click="publish" class="btn btn-primary">{{ __('racklab.publish.publish') }}</button>
            </div>
        </section>
    @endif

    <section>
        <h2 class="text-lg font-semibold text-base-content">{{ __('racklab.publish.published_heading') }}</h2>
        @if ($publishedItems === [])
            <p class="mt-2 text-sm text-base-content/70">{{ __('racklab.publish.none_published') }}</p>
        @else
            <div class="mt-3 overflow-x-auto rounded border border-base-300">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('racklab.publish.item') }}</th>
                            <th>{{ __('racklab.publish.current_version') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($publishedItems as $row)
                            <tr wire:key="item-{{ $row['item']->getKey() }}">
                                <td>
                                    <div class="font-medium">{{ $row['item']->name }}</div>
                                    <div class="text-xs text-base-content/60">{{ $row['item']->slug }}</div>
                                </td>
                                <td>{{ $row['version']?->version ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
