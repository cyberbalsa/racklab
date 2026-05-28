@extends('layouts.app')

@section('content')
    <article class="mx-auto max-w-3xl py-8" data-testid="doc-reader">
        <nav class="mb-4 text-sm">
            <a href="{{ route('dashboard') }}" class="link link-hover">{{ __('racklab.docs.back_to_dashboard') }}</a>
        </nav>

        <header class="mb-6 border-b border-base-300 pb-4">
            <h1 class="text-3xl font-semibold text-base-content">{{ $doc->title }}</h1>
            @if ($doc->published_at === null)
                <span class="badge badge-warning badge-sm mt-2" data-testid="doc-draft-badge">
                    {{ __('racklab.docs.draft_badge') }}
                </span>
            @endif
        </header>

        {{-- html_cache is produced by MarkdownRenderer with html_input=escape,
             so authored HTML is already neutralised; rendering raw is safe. --}}
        <div class="racklab-doc-body" data-testid="doc-body">
            {!! $doc->currentVersion?->html_cache !!}
        </div>
    </article>
@endsection

@push('scripts')
    @vite('resources/js/islands/racklab-ref.ts')
@endpush
