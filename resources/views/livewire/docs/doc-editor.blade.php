<div class="mx-auto max-w-6xl py-8">
    <nav class="mb-4 text-sm">
        <a href="{{ route('docs.index') }}" wire:navigate class="link link-hover">{{ __('racklab.docs.back_to_index') }}</a>
    </nav>

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-base-content">
            {{ $docId === null ? __('racklab.docs.create_title') : __('racklab.docs.edit_title') }}
        </h1>
        @if ($docId !== null)
            <span class="badge {{ $isPublished ? 'badge-success' : 'badge-warning' }} badge-sm">
                {{ $isPublished ? __('racklab.docs.status_published') : __('racklab.docs.draft_badge') }}
            </span>
        @endif
    </div>

    @if (session('docs-status'))
        <div class="alert alert-success mt-4" role="status" dusk="docs-status">{{ session('docs-status') }}</div>
    @endif

    @if ($docId === null && count($projectOptions) === 0)
        <div class="alert alert-warning mt-6" role="status" data-testid="docs-no-projects">
            {{ __('racklab.docs.no_create_projects') }}
        </div>
    @else
        <form wire:submit="save" class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="flex flex-col gap-4">
                <label class="form-control">
                    <span class="label-text font-medium">{{ __('racklab.docs.field_title') }}</span>
                    <input type="text" wire:model="title" dusk="docs-title"
                        class="input input-bordered" maxlength="255" />
                    @error('title') <span class="text-error text-sm">{{ $message }}</span> @enderror
                </label>

                @if ($docId === null)
                    <label class="form-control">
                        <span class="label-text font-medium">{{ __('racklab.docs.field_project') }}</span>
                        <select wire:model="projectId" dusk="docs-project" class="select select-bordered">
                            @foreach ($projectOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <label class="form-control">
                    <span class="label-text font-medium">{{ __('racklab.docs.field_markdown') }}</span>
                    <textarea wire:model.blur="markdown" dusk="docs-markdown" rows="16"
                        class="textarea textarea-bordered font-mono text-sm"
                        placeholder="{{ __('racklab.docs.markdown_placeholder') }}"></textarea>
                    @error('markdown') <span class="text-error text-sm">{{ $message }}</span> @enderror
                </label>

                <label class="form-control">
                    <span class="label-text font-medium">{{ __('racklab.docs.field_editor_message') }}</span>
                    <input type="text" wire:model="editorMessage" dusk="docs-editor-message"
                        class="input input-bordered input-sm" maxlength="255"
                        placeholder="{{ __('racklab.docs.editor_message_placeholder') }}" />
                </label>

                <div class="flex items-center gap-2">
                    <button type="submit" class="btn btn-primary" dusk="docs-save">
                        {{ __('racklab.docs.save') }}
                    </button>
                    @if ($docId !== null && ! $isPublished)
                        <button type="button" wire:click="publish" class="btn btn-outline" dusk="docs-publish">
                            {{ __('racklab.docs.publish') }}
                        </button>
                    @endif
                </div>
            </div>

            <div>
                <span class="label-text font-medium">{{ __('racklab.docs.preview') }}</span>
                <div class="racklab-doc-body mt-1 min-h-[20rem] rounded-lg border border-base-300 bg-base-200/40 p-4"
                    data-testid="docs-preview">
                    @if ($preview === '')
                        <p class="text-base-content/50">{{ __('racklab.docs.preview_empty') }}</p>
                    @else
                        {!! $preview !!}
                    @endif
                </div>
            </div>
        </form>
    @endif
</div>

@push('scripts')
    @vite('resources/js/islands/racklab-ref.ts')
@endpush
