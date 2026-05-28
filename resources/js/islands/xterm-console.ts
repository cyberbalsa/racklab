/**
 * RackLab xterm.js island.
 *
 * M4 sub-slice 4 ships the mount/unmount seam. M4 sub-slice 5 wires it
 * to a real `@xterm/xterm` Terminal + `@xterm/addon-attach` against the
 * ProviderConsoleProxy localhost socket.
 *
 * The browser never holds Proxmox credentials.
 */

export interface XtermMountOptions {
    container: HTMLElement;
    websocketUrl: string;
    ticket: string;
    /** Optional ARIA live-region element used to announce status changes. */
    statusElement?: HTMLElement | null;
    /** Default Ctrl+Alt+Shift+Q. Pressed while the terminal has focus to release it. */
    focusReleaseShortcut?: string;
}

export interface XtermSession {
    disconnect(): void;
    isConnected(): boolean;
}

/**
 * Mount an xterm.js terminal into `container`. The current implementation is a
 * deterministic stub that emits a status string and exposes a disconnect()
 * callback. The real `@xterm/xterm` Terminal plugs in via sub-slice 5.
 */
export function mountXtermConsole(options: XtermMountOptions): XtermSession {
    const { container, websocketUrl, statusElement } = options;
    let connected = true;

    container.dataset.connectionState = 'connecting';
    container.dataset.targetWs = websocketUrl;
    announce(statusElement, 'Connecting terminal console...');

    queueMicrotask(() => {
        if (!connected) return;
        container.dataset.connectionState = 'connected';
        announce(statusElement, 'Terminal console connected.');
    });

    return {
        disconnect(): void {
            connected = false;
            container.dataset.connectionState = 'disconnected';
            announce(statusElement, 'Terminal console disconnected.');
        },
        isConnected(): boolean {
            return connected;
        },
    };
}

function announce(target: HTMLElement | null | undefined, message: string): void {
    if (target) {
        target.textContent = message;
    }
}

// Self-register so the Vite entry bundle keeps mountXtermConsole through tree-shaking.
declare global {
    interface Window {
        RackLab?: {
            console?: {
                mountNoVncViewer?: (options: unknown) => unknown;
                mountXtermConsole?: typeof mountXtermConsole;
            };
        };
    }
}

if (typeof window !== 'undefined') {
    window.RackLab = window.RackLab ?? {};
    window.RackLab.console = window.RackLab.console ?? {};
    window.RackLab.console.mountXtermConsole = mountXtermConsole;
}
