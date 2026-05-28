<div
    class="rounded-lg border border-base-300 bg-base-200 p-4"
    data-testid="deployment-vpn-panel"
>
    <h2 class="text-lg font-semibold">
        {{ __('racklab.vpnaas.panel.title') }}
    </h2>

    @if ($rows === [])
        <p class="mt-2 text-sm text-base-content/70" data-testid="deployment-vpn-panel-empty">
            {{ __('racklab.vpnaas.panel.empty') }}
        </p>
    @else
        <ul class="mt-3 space-y-3">
            @foreach ($rows as $row)
                <li
                    class="rounded border border-base-300 bg-base-100 p-3"
                    data-testid="vpn-endpoint-row"
                    data-endpoint-id="{{ $row['endpoint']->getKey() }}"
                    data-endpoint-state="{{ $row['endpoint']->state }}"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium">{{ $row['endpoint']->name }}</p>
                            <p class="text-xs text-base-content/60">
                                {{ __('racklab.vpnaas.panel.capability', ['capability' => $row['endpoint']->capability]) }}
                                ·
                                {{ __('racklab.vpnaas.panel.state', ['state' => $row['endpoint']->state]) }}
                            </p>
                        </div>
                    </div>

                    @if ($row['bindings']->isNotEmpty())
                        <p class="mt-2 text-xs text-base-content/70">
                            {{ __('racklab.vpnaas.panel.bindings') }}:
                            @foreach ($row['bindings'] as $binding)
                                <span class="font-mono">{{ $binding->public_ip }}:{{ $binding->udp_port }}</span>@if (! $loop->last), @endif
                            @endforeach
                        </p>
                    @endif

                    @if ($row['profile'])
                        <p
                            class="mt-2 text-sm"
                            data-testid="vpn-profile-status"
                            data-profile-state="{{ $row['profile']->state }}"
                        >
                            @if ($row['profile_active'])
                                {{ __('racklab.vpnaas.panel.profile_active') }}
                            @else
                                {{ __('racklab.vpnaas.panel.profile_revoked') }}
                            @endif
                        </p>
                    @else
                        <p class="mt-2 text-sm text-base-content/70" data-testid="vpn-profile-missing">
                            {{ __('racklab.vpnaas.panel.profile_missing') }}
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
