import './bootstrap-livewire';

// Livewire 4 bundles Alpine.js. The Livewire global is registered by the
// @livewireScripts Blade directive in the layout; this bootstrap file is
// reserved for non-Livewire frontend wiring (Echo / Pusher protocol client
// lands in the realtime-replay sub-plan; vanilla JS islands are imported
// from resources/js/islands/ as they land in later sub-plans).

declare global {
    // Window type extensions go here as needed.
}

export {};
