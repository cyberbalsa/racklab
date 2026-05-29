<div class="mx-auto max-w-4xl space-y-6 py-8">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-base-content">{{ __('racklab.sharing.title') }}</h1>
        <p class="text-base text-base-content/70">{{ __('racklab.sharing.summary') }}</p>
    </header>

    @if (session('status'))
        <div dusk="sharing-status" class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($shareable === [])
        <p class="text-sm text-base-content/70">{{ __('racklab.sharing.empty') }}</p>
    @else
        @foreach ($shareable as $row)
            @php($deployment = $row['deployment'])
            <section class="rounded border border-base-300 p-4" wire:key="share-{{ $deployment->getKey() }}">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold text-base-content">{{ $deployment->name }}</h2>
                    <span class="text-xs text-base-content/60">{{ $deployment->state }}</span>
                </div>

                @if ($row['guests'] === [])
                    <p class="mt-2 text-sm text-base-content/70">{{ __('racklab.sharing.no_guests') }}</p>
                @else
                    <ul class="mt-2 flex flex-wrap gap-2">
                        @foreach ($row['guests'] as $guest)
                            <li>
                                <span class="badge badge-outline gap-1">
                                    {{ $guest['name'] }} ({{ $guest['email'] }})
                                    <button
                                        type="button"
                                        dusk="revoke-{{ $deployment->getKey() }}-{{ $guest['user_id'] }}"
                                        wire:click="revoke('{{ $deployment->getKey() }}', {{ $guest['user_id'] }})"
                                        class="text-error"
                                        title="{{ __('racklab.sharing.revoke') }}"
                                    >✕</button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <label class="form-control mt-3">
                    <span class="label-text">{{ __('racklab.sharing.add_guests') }}</span>
                    <textarea
                        dusk="emails-{{ $deployment->getKey() }}"
                        wire:model="emailInputs.{{ $deployment->getKey() }}"
                        rows="2"
                        class="textarea textarea-bordered font-mono text-sm"
                        placeholder="{{ __('racklab.sharing.emails_placeholder') }}"
                    ></textarea>
                </label>
                <div class="mt-2 flex justify-end">
                    <button type="button" dusk="share-{{ $deployment->getKey() }}" wire:click="share('{{ $deployment->getKey() }}')" class="btn btn-sm btn-primary">{{ __('racklab.sharing.share') }}</button>
                </div>
            </section>
        @endforeach
    @endif
</div>
