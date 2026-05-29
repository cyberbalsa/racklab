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
</div>
