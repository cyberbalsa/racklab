import './bootstrap-livewire';
import './islands/console-connect';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Echo?: Echo<'reverb'>;
        Pusher?: typeof Pusher;
    }

    interface ImportMetaEnv {
        readonly VITE_REVERB_APP_KEY?: string;
        readonly VITE_REVERB_HOST?: string;
        readonly VITE_REVERB_PORT?: string;
        readonly VITE_REVERB_SCHEME?: string;
    }
}

const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

if (reverbKey !== undefined && reverbKey.trim() !== '') {
    const reverbScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';

    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: reverbScheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

export {};
