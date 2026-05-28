<div
    class="rounded-lg border border-base-300 bg-base-200 p-4"
    data-testid="console-pane"
    data-deployment-id="{{ $deploymentId }}"
    data-console-kind="{{ $consoleKindValue }}"
>
    @if ($canConnect)
        <div class="flex items-center justify-between" data-testid="console-pane-authorized">
            <div>
                <h2 class="text-lg font-semibold">
                    {{ __('racklab.console.title', ['name' => $deploymentName]) }}
                </h2>
                <p class="text-sm text-base-content/70" id="console-pane-{{ $deploymentId }}-shortcut">
                    {{ __('racklab.console.focus_release_hint') }}
                </p>
            </div>
            <button
                type="button"
                class="btn btn-primary"
                data-testid="console-connect"
                dusk="console-connect-{{ $deploymentId }}"
            >
                {{ __('racklab.console.connect') }}
            </button>
        </div>

        <div
            class="mt-4 rounded border border-base-300 bg-black/90 p-2"
            data-testid="console-canvas-{{ $consoleKindValue }}"
            wire:ignore
            role="region"
            aria-label="{{ __('racklab.console.aria_label', ['kind' => $consoleKindValue]) }}"
            aria-describedby="console-pane-{{ $deploymentId }}-shortcut"
        >
            @if ($consoleKindValue === 'vnc')
                <div
                    id="novnc-viewer-{{ $deploymentId }}"
                    class="aspect-video min-h-[320px] w-full"
                    data-testid="novnc-viewer"
                ></div>
            @else
                <div
                    id="xterm-console-{{ $deploymentId }}"
                    class="min-h-[320px] w-full font-mono text-sm text-base-100"
                    data-testid="xterm-console"
                ></div>
            @endif
        </div>

        <p
            class="sr-only"
            aria-live="polite"
            data-testid="console-status"
            data-status="{{ $statusKey }}"
        >
            {{ __($statusKey) }}
        </p>
    @else
        <div
            class="rounded border border-base-300 bg-base-100 p-3 text-sm text-base-content/70"
            data-testid="console-pane-unauthorized"
            role="status"
        >
            {{ __('racklab.console.unavailable') }}
        </div>
    @endif
</div>
