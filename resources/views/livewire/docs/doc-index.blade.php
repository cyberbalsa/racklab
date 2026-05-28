<div class="mx-auto max-w-5xl py-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-base-content">{{ __('racklab.docs.index_title') }}</h1>
            <p class="mt-2 text-base-content/70">{{ __('racklab.docs.index_summary') }}</p>
        </div>
        <a href="{{ route('docs.create') }}" wire:navigate class="btn btn-primary btn-sm" dusk="docs-new">
            {{ __('racklab.docs.new_doc') }}
        </a>
    </div>

    @if (session('docs-status'))
        <div class="alert alert-success mt-4" role="status">{{ session('docs-status') }}</div>
    @endif

    <div class="mt-6 overflow-x-auto rounded-lg border border-base-300">
        @if (count($docs) === 0)
            <p class="p-4 text-base-content/70" data-testid="docs-empty">{{ __('racklab.docs.empty') }}</p>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('racklab.docs.col_title') }}</th>
                        <th>{{ __('racklab.docs.col_status') }}</th>
                        <th>{{ __('racklab.docs.col_updated') }}</th>
                        <th class="text-right">{{ __('racklab.docs.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($docs as $visible)
                        <tr data-testid="docs-row" dusk="docs-row-{{ $visible->doc->getKey() }}">
                            <td class="font-medium text-base-content">{{ $visible->doc->title }}</td>
                            <td>
                                @if ($visible->doc->published_at !== null)
                                    <span class="badge badge-success badge-sm">{{ __('racklab.docs.status_published') }}</span>
                                @else
                                    <span class="badge badge-warning badge-sm">{{ __('racklab.docs.draft_badge') }}</span>
                                @endif
                            </td>
                            <td class="text-base-content/70">{{ $visible->doc->updated_at?->diffForHumans() }}</td>
                            <td class="text-right">
                                <a href="{{ route('docs.show', $visible->doc) }}" wire:navigate class="btn btn-ghost btn-xs">
                                    {{ __('racklab.docs.read') }}
                                </a>
                                @if ($visible->canEdit)
                                    <a href="{{ route('docs.edit', $visible->doc) }}" wire:navigate class="btn btn-ghost btn-xs"
                                        dusk="docs-edit-{{ $visible->doc->getKey() }}">
                                        {{ __('racklab.docs.edit') }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
